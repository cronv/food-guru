<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Api\Validation\InputValidator;
use App\Entity\User;
use App\Enum\UserRole;
use App\Security\Attribute\VerifiedEmail;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_CONTENT_MANAGER'), VerifiedEmail]
final class AdminUserController
{
    public function __construct(
        private readonly Connection $db,
        private readonly InputValidator $validator,
    ) {
    }

    #[Route('/admin/users', name: 'admin_users_index', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$user->getRole()->atLeast(UserRole::EditorLead)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }

        $data = $this->validator->validate($request, [
            'q' => 'nullable|string|max:120',
            'role' => 'nullable|string|in:user,content_manager,editor_lead,admin',
            'verified' => 'nullable|string|in:yes,no',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $perPage = (int) ($data['per_page'] ?? 20);
        $page = max(1, (int) $request->query->get('page', 1));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];
        if (!empty($data['q'])) {
            $where[] = '(name LIKE ? OR email LIKE ?)';
            $params[] = '%'.$data['q'].'%';
            $params[] = '%'.$data['q'].'%';
        }
        if (!empty($data['role'])) {
            $where[] = 'role = ?';
            $params[] = $data['role'];
        }
        if (!empty($data['verified'])) {
            $where[] = 'yes' === $data['verified'] ? 'email_verified_at IS NOT NULL' : 'email_verified_at IS NULL';
        }
        $whereSql = $where ? 'WHERE '.implode(' AND ', $where) : '';

        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM users $whereSql", $params);
        $rows = $this->db->fetchAllAssociative(
            "SELECT id, name, email, role, is_active, email_verified_at, pending_email, created_at FROM users $whereSql ORDER BY id DESC LIMIT $perPage OFFSET $offset",
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

    #[Route('/admin/users/{id}', name: 'admin_users_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $user = $this->db->fetchAssociative('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$user) {
            return new JsonResponse(['message' => 'Not found.'], 404);
        }

        if (!$this->canManage($actor, $user)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }

        $data = $this->validator->validate($request, [
            'role' => 'nullable|string|in:user,content_manager,editor_lead,admin',
            'is_active' => 'nullable|boolean',
        ]);

        if (isset($data['role'])) {
            $newRole = UserRole::from($data['role']);
            if (!$this->canAssignRole($actor, $user, $newRole)) {
                return new JsonResponse(['message' => 'Нельзя назначить эту роль.', 'code' => 'role_not_allowed'], 403);
            }
            $before = $user['role'];
            $this->db->update('users', ['role' => $newRole->value, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
            $this->log($actor->getId(), 'user.role_changed', (int) $user['id'], ['from' => $before, 'to' => $newRole->value, 'email' => $user['email']]);
        }

        if (\array_key_exists('is_active', $data)) {
            $active = (bool) $data['is_active'];
            $this->db->update('users', ['is_active' => (int) $active, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
            if (!$active) {
                $this->db->executeStatement('DELETE FROM sessions WHERE user_id = ?', [$id]);
            }
            $this->log($actor->getId(), $active ? 'user.enabled' : 'user.disabled', (int) $user['id'], ['email' => $user['email']]);
        }

        $fresh = $this->db->fetchAssociative('SELECT id, name, email, role, is_active, email_verified_at, pending_email, created_at FROM users WHERE id = ?', [$id]);

        return new JsonResponse(['data' => $fresh]);
    }

    #[Route('/admin/users/{id}/resend-verification', name: 'admin_users_resend', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function resendVerification(int $id, #[CurrentUser] User $actor): JsonResponse
    {
        $user = $this->db->fetchAssociative('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$user) {
            return new JsonResponse(['message' => 'Not found.'], 404);
        }
        if (!$this->canManage($actor, $user)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }

        if (!empty($user['email_verified_at']) && empty($user['pending_email'])) {
            return new JsonResponse(['message' => 'Почта уже подтверждена.', 'code' => 'already_verified'], 422);
        }

        // cooldown check
        $last = $this->db->fetchAssociative(
            'SELECT created_at FROM email_tokens WHERE user_id = ? AND type = ? ORDER BY created_at DESC LIMIT 1',
            [(int) $user['id'], 'verify']
        );
        if ($last) {
            $next = (new \DateTimeImmutable($last['created_at']))->add(new \DateInterval('PT60S'));
            if ($next > new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
                return new JsonResponse(['message' => 'Подождите перед повторной отправкой.', 'code' => 'cooldown'], 429);
            }
        }

        // issue token
        $email = $user['pending_email'] ?: $user['email'];
        $this->db->executeStatement('UPDATE email_tokens SET used_at = ? WHERE user_id = ? AND type = ? AND used_at IS NULL', [date('Y-m-d H:i:s'), (int) $user['id'], 'verify']);
        $plain = bin2hex(random_bytes(32));
        $this->db->insert('email_tokens', [
            'user_id' => (int) $user['id'],
            'token_hash' => hash('sha256', $plain),
            'type' => 'verify',
            'email' => $email,
            'expires_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->add(new \DateInterval('PT24H'))->format('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->log($actor->getId(), 'user.verification_resent', (int) $user['id'], ['email' => $user['email']]);

        return new JsonResponse(['data' => ['status' => 'sent']]);
    }

    /** @param array<string, mixed> $user */
    private function canManage(User $actor, array $user): bool
    {
        if ($actor->getId() === (int) $user['id']) {
            return false;
        }
        if (UserRole::Admin === $actor->getRole()) {
            return true;
        }
        if (UserRole::EditorLead === $actor->getRole()) {
            return \in_array($user['role'], ['user', 'content_manager'], true);
        }

        return false;
    }

    /** @param array<string, mixed> $user */
    private function canAssignRole(User $actor, array $user, UserRole $newRole): bool
    {
        if (UserRole::Admin === $actor->getRole()) {
            return true;
        }
        if (UserRole::EditorLead === $actor->getRole()) {
            // cannot assign admin, cannot promote self, cannot change admin
            if (UserRole::Admin === $newRole) {
                return false;
            }
            if ('admin' === $user['role']) {
                return false;
            }

            return \in_array($newRole->value, ['user', 'content_manager'], true);
        }

        return false;
    }

    /** @param array<string, mixed> $payload */
    private function log(?int $actorId, string $action, int $subjectId, array $payload = []): void
    {
        $this->db->insert('activity_log', [
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => 'User',
            'subject_id' => $subjectId,
            'payload' => json_encode($payload) ?: null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
