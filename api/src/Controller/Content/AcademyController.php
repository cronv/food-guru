<?php

declare(strict_types=1);

namespace App\Controller\Content;

use App\Api\Pagination\Paginator;
use App\Api\Validation\InputValidator;
use App\Catalog\FullText;
use App\Catalog\PublishedRecipes;
use App\Content\AcademyPresenter;
use App\Content\Publishable;
use App\Entity\User;
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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Кулинарная академия: расписание, архив, карточка, запись на эфир. */
final class AcademyController
{
    public function __construct(
        private readonly Connection $db,
        private readonly InputValidator $validator,
        private readonly AcademyPresenter $academy,
        private readonly Security $security,
        private readonly ActivityLog $log,
        private readonly ClockInterface $clock,
    ) {
    }

    private function published(): QueryBuilder
    {
        return $this->db->createQueryBuilder()->select('s.*')->from('academy_sessions', 's')
            ->where("s.status = 'published' AND s.published_at IS NOT NULL AND s.published_at <= :now")
            ->setParameter('now', PublishedRecipes::now($this->clock->now()));
    }

    /**
     * Расписание и архив записей.
     *
     * scope=upcoming — то, что ещё не началось, ближайшее первым;
     * scope=archive  — прошедшие эфиры и видеоуроки, свежие первыми.
     */
    #[Route('/academy', name: 'academy_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $data = $this->validator->validate($request, [
            'q' => 'nullable|string|max:120',
            'format' => 'nullable|array',
            'format.*' => 'string|in:webinar,stream,lesson',
            'level' => 'nullable|array',
            'level.*' => 'string|in:basic,medium,advanced',
            'scope' => 'nullable|string|in:upcoming,archive,all',
            'page' => 'nullable|integer|min:1',
        ]);

        $qb = FullText::apply($this->published(), 's', $data['q'] ?? null);
        if (!empty($data['format'])) {
            $qb->andWhere('s.format IN (:format)')->setParameter('format', array_values($data['format']), ArrayParameterType::STRING);
        }
        if (!empty($data['level'])) {
            $qb->andWhere('s.level IN (:level)')->setParameter('level', array_values($data['level']), ArrayParameterType::STRING);
        }

        match ($data['scope'] ?? 'upcoming') {
            'upcoming' => $qb->andWhere('s.starts_at IS NOT NULL AND s.starts_at >= :now')->orderBy('s.starts_at', 'ASC'),
            // В архиве два случая: прошедший эфир и видеоурок без времени старта вовсе.
            'archive' => $qb->andWhere('(s.starts_at IS NULL OR s.starts_at < :now)')->orderBy('COALESCE(s.starts_at, s.published_at)', 'DESC'),
            default => $qb->orderBy('COALESCE(s.starts_at, s.published_at)', 'DESC'),
        };

        return new JsonResponse($this->page($request, $qb));
    }

    #[Route('/academy/{slug}', name: 'academy_show', methods: ['GET'])]
    public function show(string $slug, #[CurrentUser] ?User $user): JsonResponse
    {
        $session = $this->db->fetchAssociative('SELECT * FROM academy_sessions WHERE slug = ? LIMIT 1', [$slug]) ?: throw new NotFoundHttpException();
        if (!$this->security->isGranted(PublishableVoter::VIEW, Publishable::fromRow($session))) {
            throw new NotFoundHttpException();
        }

        return new JsonResponse(['data' => $this->academy->full($session, $user)]);
    }

    /** Мои записи на эфиры — для личного кабинета. */
    #[Route('/me/academy', name: 'academy_mine', methods: ['GET'])]
    #[IsGranted('ROLE_USER'), VerifiedEmail, TrackVisit]
    public function mine(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $qb = $this->published()
            ->andWhere('s.id IN (SELECT ar.academy_session_id FROM academy_registrations ar WHERE ar.user_id = :me)')
            ->setParameter('me', $user->getId())
            ->orderBy('COALESCE(s.starts_at, s.published_at)', 'DESC');

        return new JsonResponse($this->page($request, $qb));
    }

    /** Записаться на эфир. Без записи ссылки на эфир и запись не отдаются. */
    #[Route('/academy/{slug}/registration', name: 'academy_register', methods: ['PUT'])]
    #[IsGranted('ROLE_USER'), VerifiedEmail, TrackVisit]
    public function register(string $slug, #[CurrentUser] User $user): JsonResponse
    {
        $session = $this->findPublished($slug);

        try {
            // Запись и счётчик — одна операция: иначе оборвавшийся запрос оставит
            // счётчик расходиться с числом записей.
            $this->db->transactional(function () use ($session, $user): void {
                $now = PublishedRecipes::now($this->clock->now());
                $this->db->insert('academy_registrations', [
                    'academy_session_id' => $session['id'], 'user_id' => $user->getId(), 'created_at' => $now,
                ]);
                // increment() в Eloquent трогает и updated_at — как и здесь.
                $this->db->executeStatement('UPDATE academy_sessions SET registrations_count = registrations_count + 1, updated_at = ? WHERE id = ?', [$now, $session['id']]);
            });
            $this->log->record($user->getId(), 'academy.registered', 'AcademySession', (int) $session['id'], ['session' => $session['slug']]);
        } catch (UniqueConstraintViolationException) {
            // Повторное нажатие — не ошибка: человек и так записан.
        }

        $session = $this->findPublished($slug);

        return new JsonResponse([
            'data' => $this->academy->full($session, $user),
            'meta' => ['message' => $this->academy->isUpcoming($session)
                ? 'Вы записаны. Напомним письмом за час до начала.'
                : 'Вы записаны. Запись открыта — можно смотреть.'],
        ]);
    }

    #[Route('/academy/{slug}/registration', name: 'academy_unregister', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER'), VerifiedEmail, TrackVisit]
    public function unregister(string $slug, #[CurrentUser] User $user): JsonResponse
    {
        $session = $this->findPublished($slug);

        $this->db->transactional(function () use ($session, $user): void {
            $deleted = $this->db->executeStatement('DELETE FROM academy_registrations WHERE academy_session_id = ? AND user_id = ?', [$session['id'], $user->getId()]);
            if ($deleted > 0) {
                $this->db->executeStatement('UPDATE academy_sessions SET registrations_count = registrations_count - 1, updated_at = ? WHERE id = ?', [
                    PublishedRecipes::now($this->clock->now()), $session['id'],
                ]);
            }
        });

        return new JsonResponse(['data' => ['registered' => false]]);
    }

    /** @return array<string, mixed> */
    private function findPublished(string $slug): array
    {
        return $this->published()->andWhere('s.slug = :slug')->setParameter('slug', $slug)
            ->setMaxResults(1)->executeQuery()->fetchAssociative() ?: throw new NotFoundHttpException();
    }

    /** @return array<string, mixed> */
    private function page(Request $request, QueryBuilder $qb): array
    {
        $page = Paginator::currentPage($request);
        $total = (int) (clone $qb)->select('COUNT(*)')->resetOrderBy()->executeQuery()->fetchOne();
        $rows = $qb->setFirstResult(($page - 1) * 12)->setMaxResults(12)->executeQuery()->fetchAllAssociative();

        return Paginator::page($request, $this->academy->cards($rows), $total, $page, 12);
    }
}
