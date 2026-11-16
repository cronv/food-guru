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
final class AdminReportController
{
    private const REASONS = [
        'error' => 'Ошибка в рецепте',
        'amounts' => 'Неверные граммовки',
        'copyright' => 'Нарушение авторских прав',
        'offensive' => 'Оскорбительный контент',
        'spam' => 'Спам или реклама',
        'other' => 'Другое',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly InputValidator $validator,
    ) {
    }

    #[Route('/admin/reports', name: 'admin_reports_index', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$user->getRole()->atLeast(UserRole::EditorLead)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }

        $data = $this->validator->validate($request, [
            'status' => 'nullable|string|in:open,resolved,rejected,all',
            'page' => 'nullable|integer|min:1',
        ]);

        $status = $data['status'] ?? 'open';
        $page = max(1, (int) ($data['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $where = '';
        $params = [];
        if ('all' !== $status) {
            $where = 'WHERE rr.status = ?';
            $params[] = $status;
        }

        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM recipe_reports rr $where",
            $params
        );
        $openTotal = (int) $this->db->fetchOne("SELECT COUNT(*) FROM recipe_reports WHERE status = 'open'");

        $rows = $this->db->fetchAllAssociative(
            "SELECT rr.*, r.slug AS recipe_slug, r.title AS recipe_title, r.status AS recipe_status,
                    u.name AS author_name, u.email AS author_email,
                    ru.name AS resolver_name
             FROM recipe_reports rr
             LEFT JOIN recipes r ON r.id = rr.recipe_id
             LEFT JOIN users u ON u.id = rr.user_id
             LEFT JOIN users ru ON ru.id = rr.resolved_by
             $where
             ORDER BY rr.created_at DESC
             LIMIT $perPage OFFSET $offset",
            $params
        );

        $data = array_map(static fn (array $r) => [
            'id' => (int) $r['id'],
            'reason' => $r['reason'],
            'reason_label' => self::REASONS[$r['reason']] ?? $r['reason'],
            'message' => $r['message'],
            'status' => $r['status'],
            'created_at' => $r['created_at'],
            'resolved_at' => $r['resolved_at'],
            'recipe' => ['id' => (int) $r['recipe_id'], 'slug' => $r['recipe_slug'], 'title' => $r['recipe_title']],
            'author' => ['id' => (int) $r['user_id'], 'name' => $r['author_name'], 'email' => $r['author_email']],
            'resolver' => $r['resolver_name'],
        ], $rows);

        return new JsonResponse([
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'last_page' => max((int) ceil($total / $perPage), 1),
                'per_page' => $perPage,
                'total' => $total,
                'open_total' => $openTotal,
            ],
        ]);
    }

    #[Route('/admin/reports/{id}/resolve', name: 'admin_reports_resolve', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function resolve(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$user->getRole()->atLeast(UserRole::EditorLead)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }

        $data = $this->validator->validate($request, [
            'status' => 'required|string|in:resolved,rejected',
        ]);

        $this->db->update('recipe_reports', [
            'status' => $data['status'],
            'resolved_by' => $user->getId(),
            'resolved_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        return new JsonResponse(['data' => ['id' => $id, 'status' => $data['status']]]);
    }
}
