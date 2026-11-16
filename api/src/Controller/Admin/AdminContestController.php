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
final class AdminContestController
{
    public function __construct(
        private readonly Connection $db,
        private readonly InputValidator $validator,
    ) {
    }

    #[Route('/admin/contests', name: 'admin_contests_index', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data = $this->validator->validate($request, [
            'status' => 'nullable|string|in:draft,moderation,published,archived',
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
        if (!empty($data['q'])) {
            $where[] = 'title LIKE ?';
            $params[] = '%'.$data['q'].'%';
        }
        $whereSql = $where ? 'WHERE '.implode(' AND ', $where) : '';

        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM contests $whereSql", $params);
        $rows = $this->db->fetchAllAssociative(
            "SELECT * FROM contests $whereSql ORDER BY updated_at DESC LIMIT $perPage OFFSET $offset",
            $params
        );

        return new JsonResponse([
            'data' => array_map(static fn (array $r) => [
                'id' => (int) $r['id'],
                'slug' => $r['slug'],
                'title' => $r['title'],
                'status' => $r['status'],
                'kind' => $r['kind'],
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

    #[Route('/admin/contests/{id}', name: 'admin_contests_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $contest = $this->findContest($id);

        return new JsonResponse(['data' => $contest]);
    }

    #[Route('/admin/contests', name: 'admin_contests_store', methods: ['POST'])]
    public function store(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$user->getRole()->atLeast(UserRole::ContentManager)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }
        $data = $this->validateContest($request, true);

        $slug = $data['slug'] ?? null;
        if (empty($slug)) {
            $slug = $this->uniqueSlug($data['title']);
        } else {
            $slug = $this->uniqueSlug($slug);
        }

        $this->db->insert('contests', [
            'author_id' => $user->getId(),
            'cover_media_id' => $data['cover_media_id'] ?? null,
            'slug' => $slug,
            'title' => $data['title'],
            'lead' => $data['lead'] ?? null,
            'description' => $data['description'] ?? null,
            'rules' => $data['rules'] ?? null,
            'prize' => $data['prize'] ?? null,
            'kind' => $data['kind'] ?? 'photo',
            'status' => 'draft',
            'submissions_open_at' => $data['submissions_open_at'] ?? null,
            'submissions_close_at' => $data['submissions_close_at'] ?? null,
            'voting_open_at' => $data['voting_open_at'] ?? null,
            'voting_close_at' => $data['voting_close_at'] ?? null,
            'results_at' => $data['results_at'] ?? null,
            'max_entries_per_user' => $data['max_entries_per_user'] ?? 1,
            'entries_count' => 0,
            'votes_count' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->db->lastInsertId();

        return new JsonResponse(['data' => $this->findContest($id)], 201);
    }

    #[Route('/admin/contests/{id}', name: 'admin_contests_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $contest = $this->findContest($id);
        if (!Ownership::canEdit($user, $contest)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }

        $data = $this->validateContest($request, false);

        $fields = [];
        $params = [];
        $map = [
            'title' => 'title',
            'lead' => 'lead',
            'description' => 'description',
            'rules' => 'rules',
            'prize' => 'prize',
            'kind' => 'kind',
            'cover_media_id' => 'cover_media_id',
            'max_entries_per_user' => 'max_entries_per_user',
        ];
        foreach ($map as $k => $col) {
            if (\array_key_exists($k, $data)) {
                $fields[] = "$col = ?";
                $params[] = $data[$k];
            }
        }
        $dates = ['submissions_open_at', 'submissions_close_at', 'voting_open_at', 'voting_close_at', 'results_at'];
        foreach ($dates as $field) {
            if (\array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
            }
        }
        if (!empty($data['slug']) && $data['slug'] !== $contest['slug']) {
            $fields[] = 'slug = ?';
            $params[] = $this->uniqueSlug($data['slug']);
        }
        if ($fields) {
            $fields[] = 'updated_at = ?';
            $params[] = date('Y-m-d H:i:s');
            $params[] = $id;
            $this->db->executeStatement('UPDATE contests SET '.implode(', ', $fields).' WHERE id = ?', $params);
        }

        return new JsonResponse(['data' => $this->findContest($id)]);
    }

    #[Route('/admin/contests/{id}/status/{action}', name: 'admin_contests_move', requirements: ['id' => '\d+', 'action' => 'publish|archive|draft|moderation'], methods: ['POST'])]
    public function move(int $id, string $action, #[CurrentUser] User $user): JsonResponse
    {
        $contest = $this->findContest($id);
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
        } elseif (!Ownership::canEdit($user, $contest)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }

        $from = PublishStatus::from($contest['status']);
        if (!$from->canMoveTo($to)) {
            return new JsonResponse(['message' => 'Недопустимый переход.', 'code' => 'invalid_transition'], 422);
        }

        if (PublishStatus::Published === $to && empty($contest['submissions_open_at'])) {
            return new JsonResponse(['message' => 'Не указана дата начала приёма работ.', 'code' => 'dates_missing'], 422);
        }

        $fields = ['status = ?', 'updated_at = ?'];
        $params = [$to->value, date('Y-m-d H:i:s')];
        if (PublishStatus::Published === $to && empty($contest['published_at'])) {
            $fields[] = 'published_at = ?';
            $params[] = date('Y-m-d H:i:s');
        }
        $params[] = $id;
        $this->db->executeStatement('UPDATE contests SET '.implode(', ', $fields).' WHERE id = ?', $params);

        return new JsonResponse(['data' => ['status' => $to->value, 'status_label' => $to->label()]]);
    }

    #[Route('/admin/contests/{id}/entries', name: 'admin_contests_entries', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function entries(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$user->getRole()->atLeast(UserRole::ContentManager)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }
        $contest = $this->findContest($id);
        $data = $this->validator->validate($request, [
            'status' => 'nullable|string|in:pending,approved,rejected',
            'per_page' => 'nullable|integer|min:1|max:60',
        ]);
        $perPage = (int) ($data['per_page'] ?? 30);
        $page = max(1, (int) $request->query->get('page', 1));
        $offset = ($page - 1) * $perPage;

        $status = $data['status'] ?? 'pending';
        $total = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM contest_entries WHERE contest_id = ? AND status = ?',
            [$id, $status]
        );
        $rows = $this->db->fetchAllAssociative(
            'SELECT ce.*, u.name AS author_name FROM contest_entries ce LEFT JOIN users u ON u.id = ce.user_id WHERE ce.contest_id = ? AND ce.status = ? ORDER BY ce.created_at LIMIT ? OFFSET ?',
            [$id, $status, $perPage, $offset]
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

    #[Route('/admin/contests/{id}/entries/{entryId}/moderate', name: 'admin_contests_moderate', requirements: ['id' => '\d+', 'entryId' => '\d+'], methods: ['POST'])]
    public function moderateEntry(int $id, int $entryId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$user->getRole()->atLeast(UserRole::ContentManager)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }
        $contest = $this->findContest($id);
        $entry = $this->db->fetchAssociative('SELECT * FROM contest_entries WHERE id = ? AND contest_id = ?', [$entryId, $id]);
        if (!$entry) {
            return new JsonResponse(['message' => 'Not found.'], 404);
        }

        $data = $this->validator->validate($request, [
            'status' => 'required|string|in:approved,rejected',
            'reason' => 'nullable|string|max:300',
        ]);

        $status = $data['status'];
        $reason = 'rejected' === $status ? ($data['reason'] ?? null) : null;

        // Если отклоняем работу с голосами — обнуляем.
        if ('rejected' === $status && (int) $entry['votes_count'] > 0) {
            $this->db->executeStatement('UPDATE contests SET votes_count = votes_count - ? WHERE id = ?', [(int) $entry['votes_count'], $id]);
            $this->db->executeStatement('DELETE FROM contest_votes WHERE contest_entry_id = ?', [$entryId]);
            $this->db->executeStatement('UPDATE contest_entries SET votes_count = 0 WHERE id = ?', [$entryId]);
        }

        $this->db->update('contest_entries', [
            'status' => $status,
            'reject_reason' => $reason,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $entryId]);

        return new JsonResponse(['data' => $this->db->fetchAssociative('SELECT * FROM contest_entries WHERE id = ?', [$entryId])]);
    }

    #[Route('/admin/contests/{id}/winners', name: 'admin_contests_winners', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function setWinners(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$user->getRole()->atLeast(UserRole::EditorLead)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }
        $contest = $this->findContest($id);
        $data = $this->validator->validate($request, [
            'places' => 'present|array|max:3',
        ]);

        $places = $data['places'] ?? [];
        // Reset places
        $this->db->executeStatement('UPDATE contest_entries SET place = NULL WHERE contest_id = ?', [$id]);
        foreach ($places as $row) {
            $entryId = (int) ($row['entry_id'] ?? 0);
            $place = (int) ($row['place'] ?? 0);
            $this->db->executeStatement(
                'UPDATE contest_entries SET place = ? WHERE id = ? AND contest_id = ? AND status = ?',
                [$place, $entryId, $id, 'approved']
            );
        }

        return new JsonResponse(['data' => 'ok']);
    }

    /** @return array<string, mixed> */
    private function findContest(int $id): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM contests WHERE id = ?', [$id]);
        if (!$row) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }

        return $row;
    }

    private function uniqueSlug(string $source): string
    {
        $base = \App\Support\Slug::make($source) ?: 'contest';
        $slug = $base;
        $i = 2;
        while ((int) $this->db->fetchOne('SELECT COUNT(*) FROM contests WHERE slug = ?', [$slug]) > 0) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /** @return array<string, mixed> */
    private function validateContest(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $this->validator->validate($request, [
            'title' => $required.'|string|min:3|max:200',
            'slug' => 'nullable|string|max:200',
            'lead' => 'nullable|string|max:600',
            'description' => 'nullable|string|max:20000',
            'rules' => 'nullable|string|max:20000',
            'prize' => 'nullable|string|max:600',
            'kind' => 'nullable|in:photo,video,recipe',
            'cover_media_id' => 'nullable|integer',
            'max_entries_per_user' => 'nullable|integer|min:1|max:10',
            'submissions_open_at' => 'nullable|string',
            'submissions_close_at' => 'nullable|string',
            'voting_open_at' => 'nullable|string',
            'voting_close_at' => 'nullable|string',
            'results_at' => 'nullable|string',
        ]);
    }
}
