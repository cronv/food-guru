<?php

declare(strict_types=1);

namespace App\Controller\Content;

use App\Api\Pagination\Paginator;
use App\Api\Validation\InputValidator;
use App\Catalog\FullText;
use App\Catalog\PublishedRecipes;
use App\Catalog\RecipePresenter;
use App\Content\Publishable;
use App\Entity\User;
use App\Enum\JournalPeriod;
use App\Media\PrivateFiles;
use App\Security\PublishableVoter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Журнал: гайды недели и месяца в PDF. */
final class JournalController
{
    public function __construct(
        private readonly Connection $db,
        private readonly InputValidator $validator,
        private readonly RecipePresenter $presenter,
        private readonly Security $security,
        private readonly PrivateFiles $files,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    private function published(): QueryBuilder
    {
        return $this->db->createQueryBuilder()->select('j.*')->from('journal_issues', 'j')
            ->where("j.status = 'published' AND j.published_at IS NOT NULL AND j.published_at <= :now")
            ->setParameter('now', PublishedRecipes::now($this->clock->now()));
    }

    #[Route('/journal', name: 'journal_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $data = $this->validator->validate($request, [
            'q' => 'nullable|string|max:120',
            'year' => 'nullable|integer|min:2000|max:2100',
            'month' => 'nullable|integer|min:1|max:12',
            'period' => 'nullable|string|in:week,month',
            'page' => 'nullable|integer|min:1',
        ], ['month.min' => 'Месяц — число от 1 до 12.']);

        $qb = FullText::apply($this->published(), 'j', $data['q'] ?? null);

        // Фильтр идёт по отдельным колонкам года и месяца — так работает индекс.
        if (!empty($data['year'])) {
            $qb->andWhere('j.issue_year = :year')->setParameter('year', (int) $data['year']);
        }
        if (!empty($data['month'])) {
            $qb->andWhere('j.issue_month = :month')->setParameter('month', (int) $data['month']);
        }
        if (!empty($data['period'])) {
            $qb->andWhere('j.period = :period')->setParameter('period', $data['period']);
        }
        $qb->orderBy('j.issue_date', 'DESC')->addOrderBy('j.id', 'DESC');

        return new JsonResponse($this->page($request, $qb));
    }

    /**
     * Годы и месяцы, в которых есть выпуски. Отдельный маршрут, а не часть списка:
     * иначе фильтр пересобирался бы под уже выбранный, и выбранный месяц
     * оставался бы единственным.
     */
    #[Route('/journal/periods', name: 'journal_periods', methods: ['GET'], priority: 1)]
    public function periods(): JsonResponse
    {
        $qb = $this->published();
        $rows = (clone $qb)->select('j.issue_year, j.issue_month, COUNT(*) AS total')
            ->groupBy('j.issue_year', 'j.issue_month')
            ->orderBy('j.issue_year', 'DESC')->addOrderBy('j.issue_month', 'DESC')
            ->executeQuery()->fetchAllAssociative();

        $years = [];
        foreach ($rows as $row) {
            $year = (int) $row['issue_year'];
            $years[$year] ??= ['year' => $year, 'count' => 0, 'months' => []];
            $years[$year]['count'] += (int) $row['total'];
            $years[$year]['months'][] = [
                'month' => (int) $row['issue_month'],
                'label' => JournalPeriod::monthName((int) $row['issue_month']),
                'count' => (int) $row['total'],
            ];
        }

        $total = (int) (clone $qb)->select('COUNT(*)')->executeQuery()->fetchOne();

        return new JsonResponse(['data' => array_values($years), 'meta' => ['total' => $total]]);
    }

    #[Route('/journal/{slug}', name: 'journal_show', methods: ['GET'])]
    public function show(string $slug, #[CurrentUser] ?User $user): JsonResponse
    {
        $issue = $this->db->fetchAssociative('SELECT * FROM journal_issues WHERE slug = ? LIMIT 1', [$slug]) ?: throw new NotFoundHttpException();
        if (!$this->security->isGranted(PublishableVoter::VIEW, Publishable::fromRow($issue))) {
            throw new NotFoundHttpException();
        }

        $staff = $user?->isStaff() ?? false;
        $author = $this->db->fetchAssociative('SELECT id, name FROM users WHERE id = ?', [$issue['author_id']]);

        $out = $this->card($issue, $this->presenter->mediaByIds([$issue['cover_media_id']]));
        $out['description'] = $issue['description'];
        if ($staff) {
            $out['moderation_note'] = $issue['moderation_note'];
            // Исходное имя файла нужно редактору, чтобы понять, что он загрузил.
            $out['file_name'] = $issue['file_name'];
        }
        $out['author'] = false === $author ? null : ['id' => (int) $author['id'], 'name' => $author['name']];

        return new JsonResponse(['data' => $out]);
    }

    /**
     * Отдаёт PDF и считает скачивание. Файл лежит на закрытом диске и проходит
     * через приложение намеренно: прямая ссылка обошла бы счётчик и осталась бы
     * рабочей после снятия выпуска с публикации.
     */
    #[Route('/journal/{slug}/download', name: 'journal_download', methods: ['GET'])]
    public function download(string $slug): BinaryFileResponse
    {
        $issue = $this->published()->andWhere('j.slug = :slug')->setParameter('slug', $slug)
            ->setMaxResults(1)->executeQuery()->fetchAssociative() ?: throw new NotFoundHttpException();

        $path = ($issue['file_path'] ?? '') !== '' ? $this->files->path((string) $issue['file_disk'], (string) $issue['file_path']) : null;
        if (null === $path) {
            throw new NotFoundHttpException();
        }

        // Счётчик не должен мешать отдаче файла, если с ним что-то не так.
        try {
            // increment() в Eloquent трогает и updated_at — как и здесь.
            $this->db->executeStatement('UPDATE journal_issues SET downloads_count = downloads_count + 1, updated_at = ? WHERE id = ?', [
                PublishedRecipes::now($this->clock->now()), $issue['id'],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Счётчик скачиваний журнала', ['exception' => $e]);
        }

        return $this->files->download($path, self::downloadName((string) $issue['title']), 'application/pdf');
    }

    /** Имя файла для скачивания — как JournalIssue::downloadName(). */
    private static function downloadName(string $title): string
    {
        $base = trim((string) preg_replace('/\s+/u', ' ', $title));
        $base = (string) preg_replace('/[\\\\\/:*?"<>|]+/u', '', $base);
        $base = mb_substr($base, 0, 120);

        return ('' !== $base ? $base : 'guide').'.pdf';
    }

    /** @return array<string, mixed> */
    private function page(Request $request, QueryBuilder $qb): array
    {
        $page = Paginator::currentPage($request);
        $total = (int) (clone $qb)->select('COUNT(*)')->resetOrderBy()->executeQuery()->fetchOne();
        $rows = $qb->setFirstResult(($page - 1) * 12)->setMaxResults(12)->executeQuery()->fetchAllAssociative();
        $covers = $this->presenter->mediaByIds(array_column($rows, 'cover_media_id'));

        return Paginator::page($request, array_map(fn (array $r): array => $this->card($r, $covers), $rows), $total, $page, 12);
    }

    /**
     * JournalIssueCardResource.
     *
     * @param array<string, mixed>             $r
     * @param array<int, array<string, mixed>> $covers
     *
     * @return array<string, mixed>
     */
    private function card(array $r, array $covers): array
    {
        $period = JournalPeriod::from((string) $r['period']);
        $date = new \DateTimeImmutable((string) $r['issue_date'], new \DateTimeZone('UTC'));

        return [
            'id' => (int) $r['id'],
            'slug' => $r['slug'],
            'title' => $r['title'],
            'lead' => $r['lead'],
            'period' => $period->value,
            'period_label' => $period->label(),
            'period_range' => $period->describe($date),
            'issue_date' => $date->format('Y-m-d'),
            'issue_year' => (int) $r['issue_year'],
            'issue_month' => (int) $r['issue_month'],
            'status' => $r['status'],
            'pages' => null === $r['pages'] ? null : (int) $r['pages'],
            'file_size' => null === $r['file_size'] ? null : (int) $r['file_size'],
            'has_file' => ($r['file_path'] ?? '') !== '',
            'downloads_count' => (int) $r['downloads_count'],
            'published_at' => RecipePresenter::iso($r['published_at']),
            'cover' => $covers[(int) $r['cover_media_id']] ?? null,
        ];
    }
}
