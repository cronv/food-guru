<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\RecipeWriter;
use App\Api\Validation\InputValidator;
use App\Api\Validation\ValidationFailed;
use App\Entity\User;
use App\Enum\RecipeStatus;
use App\Enum\UserRole;
use App\Media\MediaUrls;
use App\Security\Attribute\VerifiedEmail;
use App\Support\ActivityLog;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[IsGranted('ROLE_CONTENT_MANAGER'), VerifiedEmail]
final class AdminRecipeController
{
    public function __construct(
        private readonly Connection $db,
        private readonly InputValidator $validator,
        private readonly RecipeWriter $writer,
        private readonly MediaUrls $urls,
        private readonly ActivityLog $log,
        #[Autowire(env: 'STORAGE_PATH')]
        private readonly string $root,
    ) {
    }

    #[Route('/admin/recipes', name: 'admin_recipes_index', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data = $this->validator->validate($request, [
            'status' => 'nullable|string|in:draft,moderation,published,rejected,archived',
            'q' => 'nullable|string|max:120',
            'author' => 'nullable|integer',
            'category' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $perPage = (int) ($data['per_page'] ?? 20);
        $page = max(1, (int) $request->query->get('page', 1));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        // Контент-менеджер видит только свои, кроме черновиков других авторов.
        if (!$user->getRole()->atLeast(UserRole::EditorLead)) {
            $where[] = 'r.author_id = ?';
            $params[] = $user->getId();
        }

        // Скрытые черновики обычных пользователей.
        $where[] = "(r.status != 'draft' OR EXISTS (SELECT 1 FROM users u WHERE u.id = r.author_id AND u.role != 'user'))";

        if (!empty($data['status'])) {
            $where[] = 'r.status = ?';
            $params[] = $data['status'];
        }
        if (!empty($data['q'])) {
            $where[] = 'r.title LIKE ?';
            $params[] = '%'.$data['q'].'%';
        }
        if (!empty($data['author'])) {
            $where[] = 'r.author_id = ?';
            $params[] = (int) $data['author'];
        }
        if (!empty($data['category'])) {
            $where[] = 'EXISTS (SELECT 1 FROM categories c WHERE c.id = r.category_id AND c.slug = ?)';
            $params[] = $data['category'];
        }

        /** @var string $whereSql */
        $whereSql = \count($where) > 0 ? 'WHERE '.implode(' AND ', $where) : ''; // @phpstan-ignore-line

        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM recipes r $whereSql",
            $params
        );

        $rows = $this->db->fetchAllAssociative(
            "SELECT r.*, c.slug AS category_slug, c.title AS category_title, u.name AS author_name
             FROM recipes r
             LEFT JOIN categories c ON c.id = r.category_id
             LEFT JOIN users u ON u.id = r.author_id
             $whereSql
             ORDER BY r.updated_at DESC
             LIMIT $perPage OFFSET $offset",
            $params
        );

        $cards = array_map(static fn (array $r) => [
            'id' => (int) $r['id'],
            'slug' => $r['slug'],
            'title' => $r['title'],
            'status' => $r['status'],
            'status_label' => $r['status'],
            'updated_at' => $r['updated_at'],
            'author' => $r['author_name'],
            'category' => $r['category_slug'],
        ], $rows);

        return new JsonResponse([
            'data' => $cards,
            'meta' => [
                'current_page' => $page,
                'last_page' => max((int) ceil($total / $perPage), 1),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    #[Route('/admin/recipes/{id}', name: 'admin_recipes_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $recipe = $this->findRecipe($id);
        $this->authorizeView($user, $recipe);

        $ingredients = $this->db->fetchAllAssociative(
            'SELECT * FROM recipe_ingredients WHERE recipe_id = ? ORDER BY position',
            [$id]
        );
        $steps = $this->db->fetchAllAssociative(
            'SELECT * FROM recipe_steps WHERE recipe_id = ? ORDER BY position',
            [$id]
        );
        $tags = $this->db->fetchAllAssociative(
            'SELECT t.* FROM tags t JOIN recipe_tag rt ON rt.tag_id = t.id WHERE rt.recipe_id = ?',
            [$id]
        );
        $gallery = $this->db->fetchAllAssociative(
            'SELECT m.*, rm.caption, rm.position FROM media m JOIN recipe_media rm ON rm.media_id = m.id WHERE rm.recipe_id = ? ORDER BY rm.position',
            [$id]
        );

        return new JsonResponse(['data' => [
            'id' => (int) $recipe['id'],
            'slug' => $recipe['slug'],
            'title' => $recipe['title'],
            'lead' => $recipe['lead'],
            'category_id' => $recipe['category_id'],
            'cover_media_id' => $recipe['cover_media_id'],
            'time_minutes' => $recipe['time_minutes'],
            'servings' => (int) $recipe['servings'],
            'difficulty' => $recipe['difficulty'],
            'status' => $recipe['status'],
            'og_description' => $recipe['og_description'],
            'calories' => $recipe['calories'],
            'protein' => $recipe['protein'],
            'fat' => $recipe['fat'],
            'carbs' => $recipe['carbs'],
            'is_vegetarian' => (bool) $recipe['is_vegetarian'],
            'is_gluten_free' => (bool) $recipe['is_gluten_free'],
            'moderation_note' => $recipe['moderation_note'],
            'ingredients' => $ingredients,
            'steps' => $steps,
            'tags' => array_column($tags, 'title'),
            'gallery' => $gallery,
        ]]);
    }

    #[Route('/admin/recipes', name: 'admin_recipes_store', methods: ['POST'])]
    public function store(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$user->getRole()->atLeast(UserRole::ContentManager)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }

        $data = $this->validateRecipe($request, true);

        $recipeId = $this->db->transactional(function () use ($data, $user) {
            $slug = $this->writer->uniqueSlug($data['slug'] ?? $data['title']);
            $this->db->insert('recipes', [
                'author_id' => $user->getId(),
                'category_id' => $data['category_id'] ?? null,
                'cover_media_id' => $data['cover_media_id'] ?? null,
                'slug' => $slug,
                'title' => $data['title'],
                'lead' => $data['lead'] ?? null,
                'time_minutes' => $data['time_minutes'] ?? null,
                'servings' => $data['servings'] ?? 4,
                'difficulty' => $data['difficulty'] ?? 'easy',
                'status' => 'draft',
                'og_description' => $data['og_description'] ?? null,
                'calories' => $data['calories'] ?? null,
                'protein' => $data['protein'] ?? null,
                'fat' => $data['fat'] ?? null,
                'carbs' => $data['carbs'] ?? null,
                'is_vegetarian' => (int) ($data['is_vegetarian'] ?? false),
                'is_gluten_free' => (int) ($data['is_gluten_free'] ?? false),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $recipeId = (int) $this->db->lastInsertId();
            $this->writer->syncChildren($recipeId, $data);

            return $recipeId;
        });

        $recipe = $this->findRecipe($recipeId);

        return new JsonResponse(['data' => [
            'id' => (int) $recipe['id'],
            'slug' => $recipe['slug'],
            'title' => $recipe['title'],
        ]], 201);
    }

    #[Route('/admin/recipes/{id}', name: 'admin_recipes_update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $recipe = $this->findRecipe($id);
        if (!$this->canUpdate($user, $recipe)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }

        $data = $this->validateRecipe($request, false);

        $this->db->transactional(function () use ($data, $recipe, $id) {
            $fields = [];
            $params = [];
            $map = [
                'category_id' => 'category_id',
                'cover_media_id' => 'cover_media_id',
                'title' => 'title',
                'lead' => 'lead',
                'time_minutes' => 'time_minutes',
                'servings' => 'servings',
                'difficulty' => 'difficulty',
                'og_description' => 'og_description',
                'calories' => 'calories',
                'protein' => 'protein',
                'fat' => 'fat',
                'carbs' => 'carbs',
            ];
            foreach ($map as $k => $col) {
                if (\array_key_exists($k, $data)) {
                    $fields[] = "$col = ?";
                    $params[] = $data[$k];
                }
            }
            if (\array_key_exists('is_vegetarian', $data)) {
                $fields[] = 'is_vegetarian = ?';
                $params[] = (int) $data['is_vegetarian'];
            }
            if (\array_key_exists('is_gluten_free', $data)) {
                $fields[] = 'is_gluten_free = ?';
                $params[] = (int) $data['is_gluten_free'];
            }
            if (!empty($data['slug']) && $data['slug'] !== $recipe['slug']) {
                $fields[] = 'slug = ?';
                $params[] = $this->writer->uniqueSlug($data['slug'], $id);
            }
            if ($fields) {
                $fields[] = 'updated_at = ?';
                $params[] = date('Y-m-d H:i:s');
                $params[] = $id;
                $this->db->executeStatement('UPDATE recipes SET '.implode(', ', $fields).' WHERE id = ?', $params);
            }
            $this->writer->syncChildren($id, $data);
        });

        $recipe = $this->findRecipe($id);

        return new JsonResponse(['data' => [
            'id' => (int) $recipe['id'],
            'slug' => $recipe['slug'],
            'title' => $recipe['title'],
        ]]);
    }

    #[Route('/admin/recipes/{id}/submit', name: 'admin_recipes_submit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function submit(int $id, #[CurrentUser] User $user): JsonResponse
    {
        return $this->move($id, $user, 'moderation', 'recipe.submitted');
    }

    #[Route('/admin/recipes/{id}/publish', name: 'admin_recipes_publish', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function publish(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $recipe = $this->findRecipe($id);
        if (!$this->canUpdate($user, $recipe)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }
        $resp = $this->move($id, $user, 'published', 'recipe.published');

        // award rating etc. skipped (RatingService not ported).
        return $resp;
    }

    #[Route('/admin/recipes/{id}/reject', name: 'admin_recipes_reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reject(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $recipe = $this->findRecipe($id);
        if (!$this->canUpdate($user, $recipe)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }
        $data = $this->validator->validate($request, [
            'note' => 'required|string|min:5|max:1000',
        ]);
        $this->db->update('recipes', [
            'moderation_note' => $data['note'],
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        return $this->move($id, $user, 'rejected', 'recipe.rejected', ['note' => $data['note']]);
    }

    #[Route('/admin/recipes/{id}/archive', name: 'admin_recipes_archive', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function archive(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $recipe = $this->findRecipe($id);
        if (!$this->canUpdate($user, $recipe)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }

        return $this->move($id, $user, 'archived', 'recipe.archived');
    }

    /** Загрузить видеорецепт (рилс). Прежний файл, загруженный через админку, удаляется. */
    #[Route('/admin/recipes/{id}/reel', name: 'admin_recipes_reel_upload', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function uploadReel(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $recipe = $this->findRecipe($id);
        if (!$this->canUpdate($user, $recipe)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw new ValidationFailed(['file' => ['Выберите видеофайл.']]);
        }
        $mime = $file->getMimeType();
        if (!\in_array($mime, ['video/mp4', 'video/webm'], true)) {
            throw new ValidationFailed(['file' => ['Подойдёт видео в формате MP4 или WebM.']]);
        }
        $size = $file->getSize();
        if (false === $size || $size > 81920 * 1024) {
            throw new ValidationFailed(['file' => ['Видео не должно весить больше 80 МБ.']]);
        }

        $directory = rtrim($this->root, '/').'/public/reels';
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $name = Uuid::v4()->toRfc4122().'.'.('video/webm' === $mime ? 'webm' : 'mp4');
        $file->move($directory, $name);

        $this->deleteReelFile($recipe);
        $this->db->update('recipes', ['reel_path' => 'reels/'.$name, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
        $this->log->record((int) $user->getId(), 'recipe.reel_uploaded', 'Recipe', $id, ['title' => $recipe['title']]);

        return new JsonResponse(['data' => ['reel_url' => $this->urls->url('reels/'.$name)]]);
    }

    #[Route('/admin/recipes/{id}/reel', name: 'admin_recipes_reel_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function deleteReel(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $recipe = $this->findRecipe($id);
        if (!$this->canUpdate($user, $recipe)) {
            return new JsonResponse(['message' => 'Forbidden.'], 403);
        }

        $this->deleteReelFile($recipe);
        $this->db->update('recipes', ['reel_path' => null, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
        $this->log->record((int) $user->getId(), 'recipe.reel_deleted', 'Recipe', $id, ['title' => $recipe['title']]);

        return new JsonResponse(['data' => ['reel_url' => null]]);
    }

    /** @param array<string, mixed> $recipe */
    private function deleteReelFile(array $recipe): void
    {
        // Демо-ролики из сидов лежат в той же папке, но их имена не UUID — их не трогаем.
        $path = (string) ($recipe['reel_path'] ?? '');
        if (1 === preg_match('#^reels/[0-9a-f-]{36}\.#', $path)) {
            $file = rtrim($this->root, '/').'/public/'.$path;
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function move(int $id, User $user, string $to, string $action, array $payload = []): JsonResponse
    {
        $recipe = $this->findRecipe($id);
        $from = RecipeStatus::from($recipe['status']);
        $toEnum = RecipeStatus::from($to);
        if (!$from->canMoveTo($toEnum)) {
            return new JsonResponse(['message' => 'Недопустимый переход статуса.', 'code' => 'invalid_transition'], 422);
        }
        $fields = ['status = ?', 'updated_at = ?'];
        $params = [$to, date('Y-m-d H:i:s')];
        if ('published' === $to) {
            $fields[] = 'published_at = COALESCE(published_at, ?)';
            $params[] = date('Y-m-d H:i:s');
            $fields[] = 'moderation_note = NULL';
        }
        $params[] = $id;
        $this->db->executeStatement('UPDATE recipes SET '.implode(', ', $fields).' WHERE id = ?', $params);

        return new JsonResponse(['data' => ['status' => $to, 'status_label' => $to]]);
    }

    /** @return array<string, mixed> */
    private function findRecipe(int $id): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM recipes WHERE id = ?', [$id]);
        if (!$row) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }

        return $row;
    }

    /** @param array<string, mixed> $recipe */
    private function canUpdate(User $user, array $recipe): bool
    {
        if ($user->getRole()->atLeast(UserRole::EditorLead)) {
            return true;
        }

        return (int) $recipe['author_id'] === (int) $user->getId();
    }

    /** @param array<string, mixed> $recipe */
    private function authorizeView(User $user, array $recipe): void
    {
        if (!$this->canUpdate($user, $recipe)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
        }
    }

    /** @return array<string, mixed> */
    private function validateRecipe(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $this->validator->validate($request, [
            'title' => $required.'|string|min:3|max:200',
            'slug' => 'nullable|string|max:200',
            'lead' => 'nullable|string|max:600',
            'category_id' => 'nullable|integer',
            'cover_media_id' => 'nullable|integer',
            'time_minutes' => 'nullable|integer|min:1|max:1440',
            'servings' => 'nullable|integer|min:1|max:50',
            'difficulty' => 'nullable|in:easy,medium,hard',
            'og_description' => 'nullable|string|max:300',
            'calories' => 'nullable|integer|min:0|max:5000',
            'protein' => 'nullable|integer|min:0|max:500',
            'fat' => 'nullable|integer|min:0|max:500',
            'carbs' => 'nullable|integer|min:0|max:500',
            'is_vegetarian' => 'nullable|boolean',
            'is_gluten_free' => 'nullable|boolean',
            'ingredients' => 'nullable|array|max:60',
            'steps' => 'nullable|array|max:40',
            'tags' => 'nullable|array|max:20',
            'gallery' => 'nullable|array|max:12',
        ]);
    }
}
