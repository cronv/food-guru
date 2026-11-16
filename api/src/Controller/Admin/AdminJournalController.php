<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\JournalFiles;
use App\Admin\Ownership;
use App\Api\Validation\InputValidator;
use App\Api\Validation\ValidationFailed;
use App\Entity\User;
use App\Enum\PublishStatus;
use App\Enum\UserRole;
use App\Security\Attribute\VerifiedEmail;
use App\Support\ActivityLog;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CONTENT_MANAGER'), VerifiedEmail]
final class AdminJournalController
{
    public function __construct(
        private readonly Connection $db,
        private readonly InputValidator $validator,
        private readonly JournalFiles $files,
        private readonly ActivityLog $log,
    ) {
    }

    #[Route('/admin/journal', name: 'admin_journal_index', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data = $this->validator->validate($request, [
            'status' => 'nullable|string|in:draft,moderation,published,archived',
            'period' => 'nullable|string|in:week,month',
            'year' => 'nullable|integer|min:2000|max:2100',
            'q' => 'nullable|string|max:120',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $perPage = (int) ($data['per_page'] ?? 20);
        $page = max(1, (int) $request->query->get('page', 1));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];
        if (!$user->getRole()->atLeast(UserRole::EditorLead)) {
            $where[] = 'author_id = ?';
            $params[] = $user->getId();
        }
        if (!empty($data['status'])) {
            $where[] = 'status = ?';
            $params[] = $data['status'];
        }
        if (!empty($data['period'])) {
            $where[] = 'period = ?';
            $params[] = $data['period'];
        }
        if (!empty($data['year'])) {
            $where[] = 'issue_year = ?';
            $params[] = (int) $data['year'];
        }
        if (!empty($data['q'])) {
            $where[] = 'title LIKE ?';
            $params[] = '%'.$data['q'].'%';
        }
        $whereSql = $where ? 'WHERE '.implode(' AND ', $where) : '';

        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM journal_issues $whereSql", $params);
        $rows = $this->db->fetchAllAssociative(
            "SELECT * FROM journal_issues $whereSql ORDER BY issue_date DESC, id DESC LIMIT $perPage OFFSET $offset",
            $params
        );

        return new JsonResponse([
            'data' => $rows,
            'meta' => [
                'current_page' => $page,
                'last_page' => max((int) ceil($total / $perPage), 1),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    #[Route('/admin/journal/{id}', name: 'admin_journal_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        return new JsonResponse(['data' => $this->findIssue($id)]);
    }

    #[Route('/admin/journal', name: 'admin_journal_store', methods: ['POST'])]
    public function store(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$user->getRole()->atLeast(UserRole::ContentManager)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }
        $data = $this->validateIssue($request, true);

        $issueDate = $this->normalizeIssueDate($data['issue_date'], $data['period'] ?? 'month');
        $year = (int) $issueDate->format('Y');
        $month = (int) $issueDate->format('n');

        $slug = !empty($data['slug']) ? $this->uniqueSlug($data['slug']) : $this->uniqueSlug($data['title']);

        $this->db->insert('journal_issues', [
            'author_id' => $user->getId(),
            'cover_media_id' => $data['cover_media_id'] ?? null,
            'slug' => $slug,
            'title' => $data['title'],
            'lead' => $data['lead'] ?? null,
            'description' => $data['description'] ?? null,
            'period' => $data['period'] ?? 'month',
            'issue_date' => $issueDate->format('Y-m-d'),
            'issue_year' => $year,
            'issue_month' => $month,
            'status' => 'draft',
            'downloads_count' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->db->lastInsertId();

        return new JsonResponse(['data' => $this->findIssue($id)], 201);
    }

    #[Route('/admin/journal/{id}', name: 'admin_journal_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $issue = $this->findIssue($id);
        if (!Ownership::canEdit($user, $issue)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }
        $data = $this->validateIssue($request, false);

        $fields = [];
        $params = [];
        $map = [
            'title' => 'title',
            'lead' => 'lead',
            'description' => 'description',
            'period' => 'period',
            'cover_media_id' => 'cover_media_id',
        ];
        foreach ($map as $k => $col) {
            if (\array_key_exists($k, $data)) {
                $fields[] = "$col = ?";
                $params[] = $data[$k];
            }
        }
        if (!empty($data['issue_date'])) {
            $period = $data['period'] ?? $issue['period'];
            $issueDate = $this->normalizeIssueDate($data['issue_date'], $period);
            $fields[] = 'issue_date = ?';
            $params[] = $issueDate->format('Y-m-d');
            $fields[] = 'issue_year = ?';
            $params[] = (int) $issueDate->format('Y');
            $fields[] = 'issue_month = ?';
            $params[] = (int) $issueDate->format('n');
        }
        if (!empty($data['slug']) && $data['slug'] !== $issue['slug']) {
            $fields[] = 'slug = ?';
            $params[] = $this->uniqueSlug($data['slug']);
        }
        if ($fields) {
            $fields[] = 'updated_at = ?';
            $params[] = date('Y-m-d H:i:s');
            $params[] = $id;
            $this->db->executeStatement('UPDATE journal_issues SET '.implode(', ', $fields).' WHERE id = ?', $params);
        }

        return new JsonResponse(['data' => $this->findIssue($id)]);
    }

    #[Route('/admin/journal/{id}/status/{action}', name: 'admin_journal_move', requirements: ['id' => '\d+', 'action' => 'publish|archive|draft|moderation'], methods: ['POST'])]
    public function move(int $id, string $action, #[CurrentUser] User $user): JsonResponse
    {
        $issue = $this->findIssue($id);
        $to = match ($action) {
            'publish' => PublishStatus::Published,
            'archive' => PublishStatus::Archived,
            'draft' => PublishStatus::Draft,
            'moderation' => PublishStatus::Moderation,
            default => throw new \InvalidArgumentException('Unknown action.'),
        };

        if (\in_array($to, [PublishStatus::Published, PublishStatus::Archived], true)) {
            if (!$user->getRole()->atLeast(UserRole::EditorLead)) {
                return new JsonResponse(['message' => 'Forbidden.'], 403);
            }
        } elseif (!Ownership::canEdit($user, $issue)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }

        $from = PublishStatus::from($issue['status']);
        if (!$from->canMoveTo($to)) {
            return new JsonResponse(['message' => 'Недопустимый переход.', 'code' => 'invalid_transition'], 422);
        }

        if (PublishStatus::Published === $to && empty($issue['file_path'])) {
            return new JsonResponse(['message' => 'Нет PDF файла.', 'code' => 'file_missing'], 422);
        }

        $fields = ['status = ?', 'updated_at = ?'];
        $params = [$to->value, date('Y-m-d H:i:s')];
        if (PublishStatus::Published === $to && empty($issue['published_at'])) {
            $fields[] = 'published_at = ?';
            $params[] = date('Y-m-d H:i:s');
        }
        $params[] = $id;
        $this->db->executeStatement('UPDATE journal_issues SET '.implode(', ', $fields).' WHERE id = ?', $params);

        return new JsonResponse(['data' => ['status' => $to->value, 'status_label' => $to->label()]]);
    }

    #[Route('/admin/journal/{id}/file', name: 'admin_journal_file_upload', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function uploadFile(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $issue = $this->findIssue($id);
        if (!Ownership::canEdit($user, $issue)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new ValidationFailed(['file' => ['Выберите PDF с гайдом.']]);
        }
        if ('pdf' !== strtolower($file->getClientOriginalExtension())) {
            throw new ValidationFailed(['file' => ['Подойдёт только PDF.']]);
        }
        if ('application/pdf' !== $file->getMimeType()) {
            throw new ValidationFailed(['file' => ['Файл не похож на PDF — проверьте, что он не переименован.']]);
        }
        $size = $file->getSize();
        if (false === $size || $size > 51200 * 1024) {
            throw new ValidationFailed(['file' => ['Файл должен быть меньше 50 МБ.']]);
        }

        $name = $file->getClientOriginalName();
        $this->files->attach($issue, $file, (int) $user->getId());
        $this->log->record((int) $user->getId(), 'journal.file.uploaded', 'JournalIssue', $id, ['name' => $name]);

        return new JsonResponse(['data' => $this->findIssue($id)]);
    }

    #[Route('/admin/journal/{id}/file', name: 'admin_journal_file_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function deleteFile(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $issue = $this->findIssue($id);
        if (!Ownership::canEdit($user, $issue)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }

        $this->files->forget($issue);
        $this->log->record((int) $user->getId(), 'journal.file.removed', 'JournalIssue', $id);

        return new JsonResponse(['data' => $this->findIssue($id)]);
    }

    /** Перерисовать обложку из первой страницы PDF. */
    #[Route('/admin/journal/{id}/cover', name: 'admin_journal_cover_render', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function renderCover(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $issue = $this->findIssue($id);
        if (!Ownership::canEdit($user, $issue)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }
        if (empty($issue['file_path'])) {
            return new JsonResponse(['message' => 'Сначала загрузите PDF — обложку рисовать не из чего.', 'code' => 'file_missing'], 422);
        }

        $cover = $this->files->renderCover($id, (int) $user->getId());
        if (null === $cover) {
            return new JsonResponse(['message' => 'Не удалось нарисовать обложку. Загрузите картинку вручную.', 'code' => 'render_failed'], 422);
        }

        $this->db->update('journal_issues', ['cover_media_id' => $cover, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);

        return new JsonResponse(['data' => $this->findIssue($id)]);
    }

    /** @return array<string, mixed> */
    private function findIssue(int $id): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM journal_issues WHERE id = ?', [$id]);
        if (!$row) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }

        return $row;
    }

    private function uniqueSlug(string $source): string
    {
        $base = \App\Support\Slug::make($source) ?: 'issue';
        $slug = $base;
        $i = 2;
        while ((int) $this->db->fetchOne('SELECT COUNT(*) FROM journal_issues WHERE slug = ?', [$slug]) > 0) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    private function normalizeIssueDate(string $date, string $period): \DateTimeImmutable
    {
        $dt = new \DateTimeImmutable($date);
        if ('week' === $period) {
            // понедельник
            $dow = (int) $dt->format('N');
            $dt = $dt->modify('-'.($dow - 1).' days');
        } else {
            $dt = $dt->modify('first day of this month');
        }

        return $dt->setTime(0, 0);
    }

    /** @return array<string, mixed> */
    private function validateIssue(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $this->validator->validate($request, [
            'title' => $required.'|string|min:3|max:200',
            'slug' => 'nullable|string|max:200',
            'lead' => 'nullable|string|max:600',
            'description' => 'nullable|string|max:20000',
            'period' => 'nullable|in:week,month',
            'issue_date' => $required.'|string',
            'cover_media_id' => 'nullable|integer',
        ]);
    }
}
