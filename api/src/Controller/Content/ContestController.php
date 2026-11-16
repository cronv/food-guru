<?php

declare(strict_types=1);

namespace App\Controller\Content;

use App\Api\Pagination\Paginator;
use App\Api\Validation\InputValidator;
use App\Catalog\FullText;
use App\Catalog\PublishedRecipes;
use App\Catalog\RecipePresenter;
use App\Content\ContestEntryPresenter;
use App\Content\Publishable;
use App\Entity\User;
use App\Enum\ContestKind;
use App\Enum\ContestPhase;
use App\Media\MediaStore;
use App\Security\Attribute\TrackVisit;
use App\Security\Attribute\VerifiedEmail;
use App\Security\PublishableVoter;
use App\Support\ActivityLog;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Query\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Конкурсы: список, карточка, галерея, работы и голоса. */
final class ContestController
{
    public function __construct(
        private readonly Connection $db,
        private readonly InputValidator $validator,
        private readonly RecipePresenter $presenter,
        private readonly ContestEntryPresenter $entries,
        private readonly MediaStore $media,
        private readonly ActivityLog $log,
        private readonly Security $security,
        private readonly ClockInterface $clock,
    ) {
    }

    private function published(): QueryBuilder
    {
        return $this->db->createQueryBuilder()->select('c.*')->from('contests', 'c')
            ->where("c.status = 'published' AND c.published_at IS NOT NULL AND c.published_at <= :now")
            ->setParameter('now', PublishedRecipes::now($this->clock->now()));
    }

    /**
     * Один маршрут обслуживает и активные, и архив: фаза считается из дат,
     * поэтому отбираем по датам, а подписи расставляет карточка.
     */
    #[Route('/contests', name: 'contests_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $data = $this->validator->validate($request, [
            'q' => 'nullable|string|max:120',
            'kind' => 'nullable|array',
            'kind.*' => 'string|in:photo,video,recipe',
            'scope' => 'nullable|string|in:active,archive,all',
            'page' => 'nullable|integer|min:1',
        ]);

        $qb = FullText::apply($this->published(), 'c', $data['q'] ?? null);
        if (!empty($data['kind'])) {
            $qb->andWhere('c.kind IN (:kind)')->setParameter('kind', array_values($data['kind']), ArrayParameterType::STRING);
        }

        // Архив — конкурсы с объявленными итогами. Активные — остальные, ближайший дедлайн первым.
        match ($data['scope'] ?? 'active') {
            'archive' => $qb->andWhere('c.results_at IS NOT NULL AND c.results_at <= :now')->orderBy('c.results_at', 'DESC'),
            'active' => $qb->andWhere('(c.results_at IS NULL OR c.results_at > :now)')
                ->orderBy('COALESCE(c.voting_close_at, c.submissions_close_at, c.published_at)', 'ASC'),
            default => $qb->orderBy('c.published_at', 'DESC'),
        };

        $page = Paginator::currentPage($request);
        $total = (int) (clone $qb)->select('COUNT(*)')->resetOrderBy()->executeQuery()->fetchOne();
        $rows = $qb->setFirstResult(($page - 1) * 12)->setMaxResults(12)->executeQuery()->fetchAllAssociative();
        $covers = $this->presenter->mediaByIds(array_column($rows, 'cover_media_id'));

        return new JsonResponse(Paginator::page($request, array_map(fn (array $c): array => $this->card($c, $covers), $rows), $total, $page, 12));
    }

    #[Route('/contests/{slug}', name: 'contests_show', methods: ['GET'])]
    public function show(string $slug, #[CurrentUser] ?User $user): JsonResponse
    {
        $contest = $this->db->fetchAssociative('SELECT * FROM contests WHERE slug = ? LIMIT 1', [$slug]) ?: throw new NotFoundHttpException();
        if (!$this->security->isGranted(PublishableVoter::VIEW, Publishable::fromRow($contest))) {
            throw new NotFoundHttpException();
        }

        $phase = ContestPhase::of($contest, $this->clock->now());
        $author = $this->db->fetchAssociative('SELECT id, name FROM users WHERE id = ?', [$contest['author_id']]);
        $winners = $this->db->fetchAllAssociative('SELECT * FROM contest_entries WHERE contest_id = ? AND place IS NOT NULL ORDER BY place ASC', [$contest['id']]);

        $out = $this->card($contest, $this->presenter->mediaByIds([$contest['cover_media_id']]));
        $out += [
            'description' => $contest['description'],
            'rules' => $contest['rules'],
            'phase_hint' => $phase->hint(),
            'max_entries_per_user' => (int) $contest['max_entries_per_user'],
            'accepts_entries' => $this->acceptsEntries($contest),
            'accepts_votes' => $this->acceptsVotes($contest),
        ];
        if ($user?->isStaff() ?? false) {
            $out['moderation_note'] = $contest['moderation_note'];
        }
        $out['author'] = false === $author ? null : ['id' => (int) $author['id'], 'name' => $author['name']];
        $out['winners'] = $this->entries->present($winners, $user, withAuthor: true);
        $out['viewer'] = $this->viewer($contest, $user);

        return new JsonResponse(['data' => $out]);
    }

    /** Галерея принятых работ. Сортировка по голосам или по свежести. */
    #[Route('/contests/{slug}/entries', name: 'contests_entries', methods: ['GET'])]
    public function entries(Request $request, string $slug, #[CurrentUser] ?User $user): JsonResponse
    {
        $contest = $this->findPublished($slug);

        $order = 'new' === $request->query->get('sort') ? 'created_at DESC' : 'votes_count DESC, id ASC';
        $where = "FROM contest_entries WHERE contest_id = :contest AND status = 'approved'";
        $total = (int) $this->db->fetchOne("SELECT COUNT(*) {$where}", ['contest' => $contest['id']]);
        $page = Paginator::currentPage($request);
        $rows = $this->db->fetchAllAssociative("SELECT * {$where} ORDER BY {$order} LIMIT 24 OFFSET ".(($page - 1) * 24), ['contest' => $contest['id']]);

        return new JsonResponse(Paginator::page($request, $this->entries->present($rows, $user, withAuthor: true), $total, $page, 24));
    }

    /** Свои работы во всех конкурсах — для личного кабинета. */
    #[Route('/me/contest-entries', name: 'contests_mine', methods: ['GET'])]
    #[IsGranted('ROLE_USER'), VerifiedEmail, TrackVisit]
    public function mine(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM contest_entries WHERE user_id = ?', [$user->getId()]);
        $page = Paginator::currentPage($request);
        $rows = $this->db->fetchAllAssociative('SELECT * FROM contest_entries WHERE user_id = ? ORDER BY created_at DESC LIMIT 12 OFFSET '.(($page - 1) * 12), [$user->getId()]);

        return new JsonResponse(Paginator::page($request, $this->entries->present($rows, $user, withAuthor: false, withContest: true), $total, $page, 12));
    }

    /** Прислать работу на конкурс. */
    #[Route('/contests/{slug}/entries', name: 'contests_submit', methods: ['POST'])]
    #[IsGranted('ROLE_USER'), VerifiedEmail, TrackVisit]
    public function submit(Request $request, string $slug, #[CurrentUser] User $user): JsonResponse
    {
        $contest = $this->findPublished($slug);

        if (!$this->acceptsEntries($contest)) {
            return new JsonResponse(['message' => 'Приём работ на этот конкурс закрыт.', 'code' => 'submissions_closed'], 422);
        }

        $max = (int) $contest['max_entries_per_user'];
        $sent = (int) $this->db->fetchOne('SELECT COUNT(*) FROM contest_entries WHERE contest_id = ? AND user_id = ?', [$contest['id'], $user->getId()]);
        if ($sent >= $max) {
            return new JsonResponse([
                'message' => 1 === $max ? 'Вы уже прислали работу на этот конкурс.' : "На этот конкурс можно прислать не больше {$max} работ.",
                'code' => 'entry_limit',
            ], 409);
        }

        $kind = ContestKind::from((string) $contest['kind']);
        $data = $this->validator->validate($request, [
            'title' => 'nullable|string|max:120',
            'body' => 'nullable|string|max:2000',
            'files' => 'required|array|min:1|max:'.$kind->maxFiles(),
            'files.*' => 'file|mimes:'.implode(',', $kind->mimes()).'|max:'.$kind->maxFileKb(),
        ], [
            'files.required' => 'Приложите хотя бы один файл.',
            'files.max' => 'К работе можно приложить не больше '.$kind->maxFiles().' файлов.',
            'files.*.mimes' => 'Подойдут файлы: '.implode(', ', $kind->mimes()).'.',
            'files.*.max' => 'Каждый файл должен быть меньше '.round($kind->maxFileKb() / 1024).' МБ.',
        ]);

        $entryId = $this->db->transactional(function () use ($contest, $user, $data, $max): int {
            // Повторный подсчёт под блокировкой строки конкурса: два одновременных
            // запроса иначе оба видят «0 работ» и оба проходят лимит.
            $this->db->fetchOne('SELECT id FROM contests WHERE id = ? FOR UPDATE', [$contest['id']]);
            $sent = (int) $this->db->fetchOne('SELECT COUNT(*) FROM contest_entries WHERE contest_id = ? AND user_id = ?', [$contest['id'], $user->getId()]);
            if ($sent >= $max) {
                return 0;
            }

            $now = PublishedRecipes::now($this->clock->now());
            $this->db->insert('contest_entries', [
                'contest_id' => $contest['id'], 'user_id' => $user->getId(),
                'title' => $data['title'] ?? null, 'body' => $data['body'] ?? null,
                'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $entryId = (int) $this->db->lastInsertId();

            foreach (array_values($data['files']) as $i => $file) {
                \assert($file instanceof UploadedFile);
                $media = $this->media->store($file, $user->getId(), $contest['title'].' — работа участника');
                $this->db->insert('contest_entry_media', ['contest_entry_id' => $entryId, 'media_id' => $media['id'], 'position' => $i]);
            }

            $this->db->executeStatement('UPDATE contests SET entries_count = entries_count + 1, updated_at = ? WHERE id = ?', [$now, $contest['id']]);
            $this->log->record($user->getId(), 'contest.entry.created', 'ContestEntry', $entryId, ['contest' => $contest['slug']]);

            return $entryId;
        });

        if (0 === $entryId) {
            return new JsonResponse(['message' => 'Вы уже прислали максимум работ на этот конкурс.', 'code' => 'entry_limit'], 409);
        }

        $entry = $this->db->fetchAssociative('SELECT * FROM contest_entries WHERE id = ?', [$entryId]);
        \assert(false !== $entry);

        return new JsonResponse([
            'data' => $this->entries->present([$entry], $user, withAuthor: true)[0],
            'meta' => ['message' => 'Работа отправлена. Она появится в галерее после проверки.'],
        ], 201);
    }

    /** Отдать голос. Один голос на конкурс, переголосовать нельзя. */
    #[Route('/contests/{slug}/entries/{entry}/vote', name: 'contests_vote', requirements: ['entry' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted('ROLE_USER'), VerifiedEmail, TrackVisit]
    public function vote(string $slug, int $entry, #[CurrentUser] User $user): JsonResponse
    {
        $contest = $this->findPublished($slug);
        $row = $this->db->fetchAssociative('SELECT * FROM contest_entries WHERE id = ?', [$entry]);
        if (false === $row || (int) $row['contest_id'] !== (int) $contest['id']) {
            throw new NotFoundHttpException();
        }

        if (!$this->acceptsVotes($contest)) {
            return new JsonResponse(['message' => 'Голосование по этому конкурсу закрыто.', 'code' => 'voting_closed'], 422);
        }
        if ('approved' !== $row['status']) {
            return new JsonResponse(['message' => 'За эту работу голосовать нельзя: она ещё не прошла проверку.', 'code' => 'entry_not_approved'], 422);
        }
        if ((int) $row['user_id'] === $user->getId()) {
            return new JsonResponse(['message' => 'За свою работу голосовать нельзя.', 'code' => 'own_entry'], 422);
        }

        // Уникальный индекс (contest_id, user_id) — единственная настоящая защита от
        // двойного голоса: проверка запросом не спасает от двух одновременных.
        try {
            $this->db->transactional(function () use ($contest, $entry, $user): void {
                $now = PublishedRecipes::now($this->clock->now());
                $this->db->insert('contest_votes', [
                    'contest_id' => $contest['id'], 'contest_entry_id' => $entry, 'user_id' => $user->getId(), 'created_at' => $now,
                ]);
                $this->db->executeStatement('UPDATE contest_entries SET votes_count = votes_count + 1, updated_at = ? WHERE id = ?', [$now, $entry]);
                $this->db->executeStatement('UPDATE contests SET votes_count = votes_count + 1, updated_at = ? WHERE id = ?', [$now, $contest['id']]);
            });
        } catch (UniqueConstraintViolationException) {
            return new JsonResponse(['message' => 'Вы уже голосовали в этом конкурсе.', 'code' => 'already_voted'], 409);
        }

        return new JsonResponse(['data' => [
            'entry_id' => $entry,
            'votes_count' => (int) $this->db->fetchOne('SELECT votes_count FROM contest_entries WHERE id = ?', [$entry]),
        ]]);
    }

    /**
     * Что доступно именно этому посетителю.
     *
     * @param array<string, mixed> $contest
     *
     * @return array<string, mixed>
     */
    private function viewer(array $contest, ?User $user): array
    {
        if (null === $user) {
            return ['can_submit' => false, 'can_vote' => false, 'entries_left' => 0, 'voted_entry_id' => null, 'my_entries' => []];
        }

        $mine = $this->db->fetchAllAssociative('SELECT * FROM contest_entries WHERE contest_id = ? AND user_id = ?', [$contest['id'], $user->getId()]);
        $voted = $this->db->fetchOne('SELECT contest_entry_id FROM contest_votes WHERE contest_id = ? AND user_id = ? LIMIT 1', [$contest['id'], $user->getId()]);
        $left = max(0, (int) $contest['max_entries_per_user'] - \count($mine));

        return [
            'can_submit' => $this->acceptsEntries($contest) && $user->isVerified() && $left > 0,
            'can_vote' => $this->acceptsVotes($contest) && $user->isVerified() && false === $voted,
            'entries_left' => $left,
            'voted_entry_id' => false === $voted ? null : (int) $voted,
            'my_entries' => $this->entries->present($mine, $user, withAuthor: false),
        ];
    }

    /** @param array<string, mixed> $contest */
    private function acceptsEntries(array $contest): bool
    {
        $now = $this->clock->now();

        return Publishable::fromRow($contest)->isPublic($now) && ContestPhase::Submissions === ContestPhase::of($contest, $now);
    }

    /** @param array<string, mixed> $contest */
    private function acceptsVotes(array $contest): bool
    {
        $now = $this->clock->now();

        return Publishable::fromRow($contest)->isPublic($now) && ContestPhase::Voting === ContestPhase::of($contest, $now);
    }

    /** @return array<string, mixed> */
    private function findPublished(string $slug): array
    {
        return $this->published()->andWhere('c.slug = :slug')->setParameter('slug', $slug)
            ->setMaxResults(1)->executeQuery()->fetchAssociative() ?: throw new NotFoundHttpException();
    }

    /**
     * ContestCardResource.
     *
     * @param array<string, mixed>             $c
     * @param array<int, array<string, mixed>> $covers
     *
     * @return array<string, mixed>
     */
    private function card(array $c, array $covers): array
    {
        $phase = ContestPhase::of($c, $this->clock->now());
        $kind = ContestKind::from((string) $c['kind']);
        $deadline = $phase->deadlineColumn();

        return [
            'id' => (int) $c['id'],
            'slug' => $c['slug'],
            'title' => $c['title'],
            'lead' => $c['lead'],
            'kind' => $kind->value,
            'kind_label' => $kind->label(),
            'status' => $c['status'],
            'phase' => $phase->value,
            'phase_label' => $phase->label(),
            'is_archive' => ContestPhase::Finished === $phase,
            'prize' => $c['prize'],
            'entries_count' => (int) $c['entries_count'],
            'votes_count' => (int) $c['votes_count'],
            'submissions_open_at' => RecipePresenter::iso($c['submissions_open_at']),
            'submissions_close_at' => RecipePresenter::iso($c['submissions_close_at']),
            'voting_open_at' => RecipePresenter::iso($c['voting_open_at']),
            'voting_close_at' => RecipePresenter::iso($c['voting_close_at']),
            'results_at' => RecipePresenter::iso($c['results_at']),
            'deadline_at' => null === $deadline ? null : RecipePresenter::iso($c[$deadline]),
            'published_at' => RecipePresenter::iso($c['published_at']),
            'cover' => $covers[(int) $c['cover_media_id']] ?? null,
        ];
    }
}
