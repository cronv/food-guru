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
final class AdminBookController
{
    public function __construct(
        private readonly Connection $db,
        private readonly InputValidator $validator,
    ) {
    }

    #[Route('/admin/books', name: 'admin_books_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $books = $this->db->fetchAllAssociative(
            'SELECT b.*, (SELECT COUNT(*) FROM book_chapters c WHERE c.book_id = b.id) AS chapters_count,
                    (SELECT COUNT(*) FROM book_purchases p WHERE p.book_id = b.id) AS purchases_count
             FROM books b ORDER BY b.updated_at DESC'
        );
        foreach ($books as &$b) {
            $cover = null;
            if (!empty($b['cover_media_id'])) {
                $cover = $this->db->fetchAssociative('SELECT * FROM media WHERE id = ?', [(int) $b['cover_media_id']]);
            }
            $b['cover'] = $cover;
        }

        return new JsonResponse(['data' => $books]);
    }

    #[Route('/admin/books/{id}', name: 'admin_books_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $book = $this->findBook($id);
        $chapters = $this->db->fetchAllAssociative('SELECT title, body, is_preview FROM book_chapters WHERE book_id = ? ORDER BY position', [$id]);
        $cover = null;
        if (!empty($book['cover_media_id'])) {
            $cover = $this->db->fetchAssociative('SELECT * FROM media WHERE id = ?', [(int) $book['cover_media_id']]);
        }

        return new JsonResponse(['data' => array_merge($book, [
            'cover' => $cover,
            'chapters' => $chapters,
        ])]);
    }

    #[Route('/admin/books', name: 'admin_books_store', methods: ['POST'])]
    public function store(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$user->getRole()->atLeast(UserRole::ContentManager)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }
        $data = $this->validateBook($request, null);

        // guard commerce
        if (!$user->getRole()->atLeast(UserRole::EditorLead)) {
            if (!empty($data['is_published'])) {
                return new JsonResponse(['message' => 'Публикация доступна редакции.', 'code' => 'publish_forbidden'], 403);
            }
        }

        $this->db->beginTransaction();
        try {
            $slug = !empty($data['slug']) ? $this->uniqueSlug($data['slug']) : $this->uniqueSlug($data['title']);
            $this->db->insert('books', [
                'slug' => $slug,
                'title' => $data['title'],
                'author_name' => $data['author_name'] ?? null,
                'year' => $data['year'] ?? null,
                'country' => $data['country'] ?? null,
                'lead' => $data['lead'] ?? null,
                'description' => $data['description'] ?? null,
                'price' => $data['price'] ?? 0,
                'in_subscription' => (int) ($data['in_subscription'] ?? false),
                'cover_media_id' => $data['cover_media_id'] ?? null,
                'is_published' => (int) ($data['is_published'] ?? false),
                'published_at' => !empty($data['is_published']) ? date('Y-m-d H:i:s') : null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $bookId = (int) $this->db->lastInsertId();
            $this->syncChapters($bookId, $data['chapters'] ?? []);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->show($bookId);
    }

    #[Route('/admin/books/{id}', name: 'admin_books_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $book = $this->findBook($id);
        if (!$user->getRole()->atLeast(UserRole::ContentManager)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }

        $data = $this->validateBook($request, $book);

        // guard commerce
        if (!$user->getRole()->atLeast(UserRole::EditorLead)) {
            $published = (bool) $book['is_published'];
            if ($published) {
                foreach (['price', 'in_subscription'] as $f) {
                    if (\array_key_exists($f, $data) && (string) $data[$f] !== (string) $book[$f]) {
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

        $this->db->beginTransaction();
        try {
            $fields = [];
            $params = [];
            $map = [
                'title' => 'title',
                'author_name' => 'author_name',
                'year' => 'year',
                'country' => 'country',
                'lead' => 'lead',
                'description' => 'description',
                'price' => 'price',
                'in_subscription' => 'in_subscription',
                'cover_media_id' => 'cover_media_id',
                'is_published' => 'is_published',
            ];
            foreach ($map as $k => $col) {
                if (\array_key_exists($k, $data)) {
                    $fields[] = "$col = ?";
                    $params[] = 'in_subscription' === $k || 'is_published' === $k ? (int) $data[$k] : $data[$k];
                }
            }
            if (!empty($data['slug']) && $data['slug'] !== $book['slug']) {
                $fields[] = 'slug = ?';
                $params[] = $this->uniqueSlug($data['slug']);
            }
            if (\array_key_exists('is_published', $data) && $data['is_published'] && empty($book['published_at'])) {
                $fields[] = 'published_at = ?';
                $params[] = date('Y-m-d H:i:s');
            }
            if ($fields) {
                $fields[] = 'updated_at = ?';
                $params[] = date('Y-m-d H:i:s');
                $params[] = $id;
                $this->db->executeStatement('UPDATE books SET '.implode(', ', $fields).' WHERE id = ?', $params);
            }
            if (\array_key_exists('chapters', $data)) {
                $this->syncChapters($id, $data['chapters']);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->show($id);
    }

    /** @param list<array<string, mixed>> $chapters */
    private function syncChapters(int $bookId, array $chapters): void
    {
        $this->db->executeStatement('DELETE FROM book_chapters WHERE book_id = ?', [$bookId]);
        foreach ($chapters as $i => $c) {
            $this->db->insert('book_chapters', [
                'book_id' => $bookId,
                'position' => $i + 1,
                'title' => (string) $c['title'],
                'body' => (string) $c['body'],
                'is_preview' => (int) ($c['is_preview'] ?? false),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function findBook(int $id): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM books WHERE id = ?', [$id]);
        if (!$row) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }

        return $row;
    }

    private function uniqueSlug(string $source): string
    {
        $base = \App\Support\Slug::make($source) ?: 'kniga';
        $slug = $base;
        $i = 2;
        while ((int) $this->db->fetchOne('SELECT COUNT(*) FROM books WHERE slug = ?', [$slug]) > 0) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /** @param array<string, mixed>|null $book
     *  @return array<string, mixed> */
    private function validateBook(Request $request, ?array $book = null): array
    {
        $creating = null === $book;
        $required = $creating ? 'required' : 'sometimes';

        return $this->validator->validate($request, [
            'title' => $required.'|string|max:200',
            'slug' => 'nullable|string|max:160',
            'author_name' => 'nullable|string|max:160',
            'year' => 'nullable|integer',
            'country' => 'nullable|string|max:80',
            'lead' => 'nullable|string|max:400',
            'description' => 'nullable|string|max:20000',
            'price' => 'nullable|integer|min:0|max:10000000',
            'in_subscription' => 'nullable|boolean',
            'cover_media_id' => 'nullable|integer',
            'is_published' => 'nullable|boolean',
            'chapters' => 'nullable|array|max:200',
        ]);
    }
}
