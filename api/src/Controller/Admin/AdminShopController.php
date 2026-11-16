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
final class AdminShopController
{
    public function __construct(
        private readonly Connection $db,
        private readonly InputValidator $validator,
    ) {
    }

    #[Route('/admin/products', name: 'admin_shop_products', methods: ['GET'])]
    public function products(Request $request): JsonResponse
    {
        $q = $request->query->get('q');
        $where = '';
        $params = [];
        if ($q) {
            $where = 'WHERE title LIKE ?';
            $params[] = '%'.$q.'%';
        }
        $rows = $this->db->fetchAllAssociative(
            "SELECT p.*, m.* FROM products p LEFT JOIN media m ON m.id = p.cover_media_id $where ORDER BY p.title",
            $params
        );

        return new JsonResponse(['data' => $rows]);
    }

    #[Route('/admin/products/{id}', name: 'admin_shop_product', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function product(int $id): JsonResponse
    {
        $row = $this->db->fetchAssociative('SELECT * FROM products WHERE id = ?', [$id]);
        if (!$row) {
            return new JsonResponse(['message' => 'Not found.'], 404);
        }

        return new JsonResponse(['data' => $row]);
    }

    #[Route('/admin/products', name: 'admin_shop_product_store', methods: ['POST'])]
    public function storeProduct(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$user->getRole()->atLeast(UserRole::ContentManager)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }
        $data = $this->validateProduct($request, null);

        // guard commerce
        if (!$user->getRole()->atLeast(UserRole::EditorLead)) {
            if (!empty($data['is_published'])) {
                return new JsonResponse(['message' => 'Публикация доступна редакции.', 'code' => 'publish_forbidden'], 403);
            }
        }

        $slug = !empty($data['slug']) ? $this->uniqueSlug($data['slug']) : $this->uniqueSlug($data['title']);

        $this->db->insert('products', [
            'slug' => $slug,
            'title' => $data['title'],
            'category' => $data['category'] ?? null,
            'material' => $data['material'] ?? null,
            'country' => $data['country'] ?? null,
            'color' => $data['color'] ?? null,
            'lead' => $data['lead'] ?? null,
            'description' => $data['description'] ?? null,
            'sku' => $data['sku'] ?? null,
            'price' => (int) ($data['price'] ?? 0),
            'old_price' => $data['old_price'] ?? null,
            'stock' => (int) ($data['stock'] ?? 0),
            'cover_media_id' => $data['cover_media_id'] ?? null,
            'is_published' => (int) ($data['is_published'] ?? false),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->db->lastInsertId();

        return new JsonResponse(['data' => $this->db->fetchAssociative('SELECT * FROM products WHERE id = ?', [$id])], 201);
    }

    #[Route('/admin/products/{id}', name: 'admin_shop_product_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function updateProduct(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $product = $this->db->fetchAssociative('SELECT * FROM products WHERE id = ?', [$id]);
        if (!$product) {
            return new JsonResponse(['message' => 'Not found.'], 404);
        }
        if (!$user->getRole()->atLeast(UserRole::ContentManager)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }
        $data = $this->validateProduct($request, $product);

        // guard commerce
        if (!$user->getRole()->atLeast(UserRole::EditorLead)) {
            $published = (bool) $product['is_published'];
            if ($published) {
                foreach (['price', 'old_price'] as $f) {
                    if (\array_key_exists($f, $data) && (string) $data[$f] !== (string) $product[$f]) {
                        return new JsonResponse(['message' => 'Изменение цены запрещено.', 'code' => 'price_forbidden'], 403);
                    }
                }
                if (\array_key_exists('is_published', $data) && !$data['is_published']) {
                    return new JsonResponse(['message' => 'Снятие с публикации запрещено.', 'code' => 'publish_forbidden'], 403);
                }
            } else {
                if (!empty($data['is_published'])) {
                    return new JsonResponse(['message' => 'Публикация доступна редакции.', 'code' => 'publish_forbidden'], 403);
                }
            }
        }

        $fields = [];
        $params = [];
        $map = [
            'title' => 'title',
            'category' => 'category',
            'material' => 'material',
            'country' => 'country',
            'color' => 'color',
            'lead' => 'lead',
            'description' => 'description',
            'sku' => 'sku',
            'price' => 'price',
            'old_price' => 'old_price',
            'stock' => 'stock',
            'cover_media_id' => 'cover_media_id',
            'is_published' => 'is_published',
        ];
        foreach ($map as $k => $col) {
            if (\array_key_exists($k, $data)) {
                $fields[] = "$col = ?";
                $params[] = 'is_published' === $k ? (int) $data[$k] : $data[$k];
            }
        }
        if (!empty($data['slug']) && $data['slug'] !== $product['slug']) {
            $fields[] = 'slug = ?';
            $params[] = $this->uniqueSlug($data['slug']);
        }
        if ($fields) {
            $fields[] = 'updated_at = ?';
            $params[] = date('Y-m-d H:i:s');
            $params[] = $id;
            $this->db->executeStatement('UPDATE products SET '.implode(', ', $fields).' WHERE id = ?', $params);
        }

        return new JsonResponse(['data' => $this->db->fetchAssociative('SELECT * FROM products WHERE id = ?', [$id])]);
    }

    #[Route('/admin/orders', name: 'admin_shop_orders', methods: ['GET'])]
    public function orders(Request $request): JsonResponse
    {
        $status = $request->query->get('status', 'active');
        $where = '';
        $params = [];
        if ('active' === $status) {
            $where = "WHERE status IN ('paid','assembling','shipped')";
        } elseif ('all' !== $status) {
            $where = 'WHERE status = ?';
            $params[] = $status;
        }
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;
        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM orders $where", $params);
        $rows = $this->db->fetchAllAssociative(
            "SELECT o.*, u.name AS user_name, u.email AS user_email FROM orders o LEFT JOIN users u ON u.id = o.user_id $where ORDER BY o.created_at DESC LIMIT $perPage OFFSET $offset",
            $params
        );
        foreach ($rows as &$r) {
            $r['items'] = $this->db->fetchAllAssociative('SELECT * FROM order_items WHERE order_id = ?', [(int) $r['id']]);
        }

        return new JsonResponse([
            'data' => $rows,
            'meta' => [
                'current_page' => $page,
                'last_page' => max((int) ceil($total / $perPage), 1),
                'total' => $total,
            ],
        ]);
    }

    #[Route('/admin/orders/{id}', name: 'admin_shop_order_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function updateOrder(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $order = $this->db->fetchAssociative('SELECT * FROM orders WHERE id = ?', [$id]);
        if (!$order) {
            return new JsonResponse(['message' => 'Not found.'], 404);
        }
        $data = $this->validator->validate($request, [
            'status' => 'required|string',
        ]);
        $newStatus = $data['status'];

        // cancel handled simply
        if ('canceled' === $newStatus) {
            // refund logic simplified: mark canceled
            $this->db->update('orders', ['status' => 'canceled', 'canceled_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);

            return new JsonResponse(['data' => $this->db->fetchAssociative('SELECT * FROM orders WHERE id = ?', [$id])]);
        }

        $this->db->beginTransaction();
        try {
            $fresh = $this->db->fetchAssociative('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$id]);
            // basic transition check
            $stamps = ['shipped' => 'shipped_at', 'delivered' => 'delivered_at'];
            $fields = ['status = ?', 'updated_at = ?'];
            $params = [$newStatus, date('Y-m-d H:i:s')];
            if (isset($stamps[$newStatus])) {
                $fields[] = $stamps[$newStatus].' = ?';
                $params[] = date('Y-m-d H:i:s');
            }
            $params[] = $id;
            $this->db->executeStatement('UPDATE orders SET '.implode(', ', $fields).' WHERE id = ?', $params);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return new JsonResponse(['data' => $this->db->fetchAssociative('SELECT * FROM orders WHERE id = ?', [$id])]);
    }

    private function uniqueSlug(string $source): string
    {
        $base = \App\Support\Slug::make($source) ?: 'tovar';
        $slug = $base;
        $i = 2;
        while ((int) $this->db->fetchOne('SELECT COUNT(*) FROM products WHERE slug = ?', [$slug]) > 0) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /** @param array<string, mixed>|null $product
     *  @return array<string, mixed> */
    private function validateProduct(Request $request, ?array $product = null): array
    {
        $creating = null === $product;
        $required = $creating ? 'required' : 'sometimes';

        return $this->validator->validate($request, [
            'title' => $required.'|string|max:200',
            'slug' => 'nullable|string|max:160',
            'category' => 'nullable|string|max:80',
            'material' => 'nullable|string|max:80',
            'country' => 'nullable|string|max:80',
            'color' => 'nullable|string|max:40',
            'lead' => 'nullable|string|max:400',
            'description' => 'nullable|string|max:20000',
            'sku' => 'nullable|string|max:64',
            'price' => $required.'|integer|min:100|max:100000000',
            'old_price' => 'nullable|integer',
            'stock' => 'nullable|integer|min:0|max:100000',
            'cover_media_id' => 'nullable|integer',
            'is_published' => 'nullable|boolean',
        ]);
    }
}
