<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Ownership;
use App\Api\Validation\InputValidator;
use App\Entity\User;
use App\Enum\PublishStatus;
use App\Enum\UserRole;
use App\Security\Attribute\VerifiedEmail;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CONTENT_MANAGER'), VerifiedEmail]
final class AdminAcademyController
{
    public function __construct(
        private readonly Connection $db,
        private readonly InputValidator $validator,
    ) {
    }

    #[Route('/admin/academy', name: 'admin_academy_index', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data = $this->validator->validate($request, [
            'status' => 'nullable|string|in:draft,moderation,published,archived',
            'format' => 'nullable|string|in:webinar,stream,lesson',
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
        if (!empty($data['format'])) {
            $where[] = 'format = ?';
            $params[] = $data['format'];
        }
        if (!empty($data['q'])) {
            $where[] = 'title LIKE ?';
            $params[] = '%'.$data['q'].'%';
        }
        $whereSql = $where ? 'WHERE '.implode(' AND ', $where) : '';

        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM academy_sessions $whereSql", $params);
        $rows = $this->db->fetchAllAssociative(
            "SELECT * FROM academy_sessions $whereSql ORDER BY updated_at DESC LIMIT $perPage OFFSET $offset",
            $params
        );

        return new JsonResponse([
            'data' => array_map(static fn (array $r) => [
                'id' => (int) $r['id'],
                'slug' => $r['slug'],
                'title' => $r['title'],
                'status' => $r['status'],
                'format' => $r['format'],
                'updated_at' => $r['updated_at'],
            ], $rows),
            'meta' => [
                'current_page' => $page,
                'last_page' => max((int) ceil($total / $perPage), 1),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    #[Route('/admin/academy/{id}', name: 'admin_academy_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $row = $this->findSession($id);

        return new JsonResponse(['data' => $row]);
    }

    #[Route('/admin/academy', name: 'admin_academy_store', methods: ['POST'])]
    public function store(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$user->getRole()->atLeast(UserRole::ContentManager)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }
        $data = $this->validateSession($request, true);

        $slug = !empty($data['slug']) ? $this->uniqueSlug($data['slug']) : $this->uniqueSlug($data['title']);

        $this->db->insert('academy_sessions', [
            'author_id' => $user->getId(),
            'cover_media_id' => $data['cover_media_id'] ?? null,
            'slug' => $slug,
            'title' => $data['title'],
            'lead' => $data['lead'] ?? null,
            'description' => $data['description'] ?? null,
            'takeaways' => $data['takeaways'] ?? null,
            'format' => $data['format'] ?? 'webinar',
            'level' => $data['level'] ?? 'basic',
            'status' => 'draft',
            'host_name' => $data['host_name'] ?? null,
            'host_title' => $data['host_title'] ?? null,
            'starts_at' => $data['starts_at'] ?? null,
            'duration_minutes' => $data['duration_minutes'] ?? null,
            'stream_url' => $data['stream_url'] ?? null,
            'record_url' => $data['record_url'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->db->lastInsertId();

        return new JsonResponse(['data' => $this->findSession($id)], 201);
    }

    #[Route('/admin/academy/{id}', name: 'admin_academy_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $session = $this->findSession($id);
        if (!Ownership::canEdit($user, $session)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }
        $data = $this->validateSession($request, false);

        $fields = [];
        $params = [];
        $map = [
            'title' => 'title',
            'lead' => 'lead',
            'description' => 'description',
            'format' => 'format',
            'level' => 'level',
            'cover_media_id' => 'cover_media_id',
            'host_name' => 'host_name',
            'host_title' => 'host_title',
            'duration_minutes' => 'duration_minutes',
        ];
        foreach ($map as $k => $col) {
            if (\array_key_exists($k, $data)) {
                $fields[] = "$col = ?";
                $params[] = $data[$k];
            }
        }
        foreach (['starts_at', 'stream_url', 'record_url', 'takeaways'] as $field) {
            if (\array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }
        if (!empty($data['slug']) && $data['slug'] !== $session['slug']) {
            $fields[] = 'slug = ?';
            $params[] = $this->uniqueSlug($data['slug']);
        }
        if ($fields) {
            $fields[] = 'updated_at = ?';
            $params[] = date('Y-m-d H:i:s');
            $params[] = $id;
            $this->db->executeStatement('UPDATE academy_sessions SET '.implode(', ', $fields).' WHERE id = ?', $params);
        }

        return new JsonResponse(['data' => $this->findSession($id)]);
    }

    #[Route('/admin/academy/{id}/status/{action}', name: 'admin_academy_move', requirements: ['id' => '\d+', 'action' => 'publish|archive|draft|moderation'], methods: ['POST'])]
    public function move(int $id, string $action, #[CurrentUser] User $user): JsonResponse
    {
        $session = $this->findSession($id);
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
        } elseif (!Ownership::canEdit($user, $session)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }

        $from = PublishStatus::from($session['status']);
        if (!$from->canMoveTo($to)) {
            return new JsonResponse(['message' => 'Недопустимый переход.', 'code' => 'invalid_transition'], 422);
        }

        // publish blocker
        if (PublishStatus::Published === $to) {
            $format = $session['format'];
            if (\in_array($format, ['webinar', 'stream'], true) && empty($session['starts_at'])) {
                return new JsonResponse(['message' => 'Не указана дата эфира.', 'code' => 'incomplete'], 422);
            }
            if ('lesson' === $format && empty($session['record_url'])) {
                return new JsonResponse(['message' => 'Для видеоурока нужна ссылка на запись.', 'code' => 'incomplete'], 422);
            }
            if (\in_array($format, ['webinar', 'stream'], true) && empty($session['stream_url']) && empty($session['record_url'])) {
                return new JsonResponse(['message' => 'Нужна ссылка на эфир или запись.', 'code' => 'incomplete'], 422);
            }
        }

        $fields = ['status = ?', 'updated_at = ?'];
        $params = [$to->value, date('Y-m-d H:i:s')];
        if (PublishStatus::Published === $to && empty($session['published_at'])) {
            $fields[] = 'published_at = ?';
            $params[] = date('Y-m-d H:i:s');
        }
        $params[] = $id;
        $this->db->executeStatement('UPDATE academy_sessions SET '.implode(', ', $fields).' WHERE id = ?', $params);

        return new JsonResponse(['data' => ['status' => $to->value, 'status_label' => $to->label()]]);
    }

    #[Route('/admin/academy/{id}/registrations', name: 'admin_academy_registrations', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function registrations(int $id, #[CurrentUser] User $user): JsonResponse
    {
        if (!$user->getRole()->atLeast(UserRole::EditorLead)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT ar.*, u.name, u.email, u.email_verified_at FROM academy_registrations ar LEFT JOIN users u ON u.id = ar.user_id WHERE ar.academy_session_id = ? ORDER BY ar.created_at DESC',
            [$id]
        );

        return new JsonResponse(['data' => $rows]);
    }

    /** @return array<string, mixed> */
    private function findSession(int $id): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM academy_sessions WHERE id = ?', [$id]);
        if (!$row) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }

        return $row;
    }

    private function uniqueSlug(string $source): string
    {
        $base = \App\Support\Slug::make($source) ?: 'lesson';
        $slug = $base;
        $i = 2;
        while ((int) $this->db->fetchOne('SELECT COUNT(*) FROM academy_sessions WHERE slug = ?', [$slug]) > 0) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /** @return array<string, mixed> */
    private function validateSession(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $this->validator->validate($request, [
            'title' => $required.'|string|min:3|max:200',
            'slug' => 'nullable|string|max:200',
            'lead' => 'nullable|string|max:600',
            'description' => 'nullable|string|max:20000',
            'takeaways' => 'nullable',
            'format' => 'nullable|in:webinar,stream,lesson',
            'level' => 'nullable|in:basic,medium,advanced',
            'cover_media_id' => 'nullable|integer',
            'host_name' => 'nullable|string|max:160',
            'host_title' => 'nullable|string|max:160',
            'starts_at' => 'nullable|string',
            'duration_minutes' => 'nullable|integer|min:5|max:600',
            'stream_url' => 'nullable|string|max:500',
            'record_url' => 'nullable|string|max:500',
        ]);
    }
}
