<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Api\Validation\InputValidator;
use App\Api\Validation\ValidationFailed;
use App\Catalog\PublishedRecipes;
use App\Catalog\RecipePresenter;
use App\Catalog\RecipeWriter;
use App\Entity\User;
use App\Media\MediaStore;
use App\Security\Attribute\RouteThrottle;
use App\Security\Attribute\TrackVisit;
use App\Security\Attribute\VerifiedEmail;
use App\Support\ActivityLog;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Рецепты читателя: черновик → на модерацию → редакция публикует или возвращает.
 *
 * Отличия от админки: читатель правит только свои черновики и возвращённые
 * рецепты; фото — только свои; без адреса, SEO-описания и «секретов шефа»;
 * опубликованное не правит — иначе можно было бы обойти модерацию.
 */
#[IsGranted('ROLE_USER'), VerifiedEmail, TrackVisit]
final class MyRecipeController
{
    /** Сколько рецептов одновременно может быть в работе — защита от потока черновиков. */
    private const OPEN_LIMIT = 20;

    private const STATUS_LABELS = [
        'draft' => 'Черновик', 'moderation' => 'На модерации', 'published' => 'Опубликован', 'rejected' => 'Возвращён', 'archived' => 'Архив',
    ];

    /** Простые поля рецепта из запроса; отсутствующие ключи не трогаются. */
    private const FIELDS = ['title', 'lead', 'category_id', 'cover_media_id', 'time_minutes', 'servings', 'difficulty',
        'calories', 'protein', 'fat', 'carbs', 'is_vegetarian', 'is_gluten_free'];

    public function __construct(
        private readonly Connection $db,
        private readonly InputValidator $validator,
        private readonly RecipeWriter $writer,
        private readonly RecipePresenter $presenter,
        private readonly MediaStore $media,
        private readonly ActivityLog $log,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/me/recipes', name: 'my_recipes', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): JsonResponse
    {
        $rows = $this->db->fetchAllAssociative('SELECT * FROM recipes WHERE author_id = ? ORDER BY updated_at DESC', [$user->getId()]);
        $covers = $this->presenter->mediaByIds(array_column($rows, 'cover_media_id'));

        return new JsonResponse(['data' => array_map(fn (array $r): array => $this->row($r, $covers), $rows)]);
    }

    #[Route('/me/recipes/{recipe}', name: 'my_recipe_show', requirements: ['recipe' => Requirement::DIGITS], methods: ['GET'])]
    public function show(int $recipe, #[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(['data' => $this->detail($this->own($recipe, $user))]);
    }

    #[Route('/me/recipes', name: 'my_recipe_store', methods: ['POST'])]
    #[RouteThrottle(limit: 30, minutes: 60)]
    public function store(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $open = (int) $this->db->fetchOne("SELECT COUNT(*) FROM recipes WHERE author_id = ? AND status IN ('draft', 'moderation', 'rejected')", [$user->getId()]);
        if ($open >= self::OPEN_LIMIT) {
            return new JsonResponse(['message' => 'У вас уже '.self::OPEN_LIMIT.' рецептов в работе. Отправьте или удалите часть черновиков.'], 422);
        }

        $data = $this->validated($request, $user, creating: true);

        $id = $this->db->transactional(function () use ($data, $user): int {
            $now = PublishedRecipes::now($this->clock->now());
            $this->db->insert('recipes', $this->fields($data) + [
                'author_id' => $user->getId(),
                'slug' => $this->writer->uniqueSlug((string) $data['title']),
                'status' => 'draft',
                'servings' => $data['servings'] ?? 4,
                'difficulty' => $data['difficulty'] ?? 'easy',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $this->db->lastInsertId();
            $this->writer->syncChildren($id, $data);
            $this->log->record($user->getId(), 'recipe.created', 'Recipe', $id, ['title' => $data['title']]);

            return $id;
        });

        return new JsonResponse(['data' => $this->detail($this->own($id, $user))], 201);
    }

    #[Route('/me/recipes/{recipe}', name: 'my_recipe_update', requirements: ['recipe' => Requirement::DIGITS], methods: ['PATCH'])]
    public function update(Request $request, int $recipe, #[CurrentUser] User $user): JsonResponse
    {
        $row = $this->own($recipe, $user);
        $this->editable($row);
        $data = $this->validated($request, $user, creating: false);

        $this->db->transactional(function () use ($data, $row, $user): void {
            $fields = $this->fields($data);
            // Адрес следует за названием, пока рецепт не опубликован.
            if (isset($data['title'])) {
                $fields['slug'] = $this->writer->uniqueSlug((string) $data['title'], (int) $row['id']);
            }
            $this->db->update('recipes', $fields + ['updated_at' => PublishedRecipes::now($this->clock->now())], ['id' => $row['id']]);
            $this->writer->syncChildren((int) $row['id'], $data);
            $this->log->record($user->getId(), 'recipe.updated', 'Recipe', (int) $row['id'], ['title' => $data['title'] ?? $row['title']]);
        });

        return new JsonResponse(['data' => $this->detail($this->own($recipe, $user))]);
    }

    /** Отправить на модерацию. Перед этим — проверка полноты: пустое редакции не нужно. */
    #[Route('/me/recipes/{recipe}/submit', name: 'my_recipe_submit', requirements: ['recipe' => Requirement::DIGITS], methods: ['POST'])]
    public function submit(int $recipe, #[CurrentUser] User $user): JsonResponse
    {
        $row = $this->own($recipe, $user);
        $this->editable($row);

        $ingredients = (int) $this->db->fetchOne('SELECT COUNT(*) FROM recipe_ingredients WHERE recipe_id = ?', [$recipe]);
        $steps = (int) $this->db->fetchOne('SELECT COUNT(*) FROM recipe_steps WHERE recipe_id = ?', [$recipe]);
        $missing = array_filter([
            'cover_media_id' => $row['cover_media_id'] ? null : 'Добавьте фото готового блюда.',
            'lead' => mb_strlen((string) $row['lead']) >= 20 ? null : 'Напишите пару предложений о блюде — от 20 символов.',
            'ingredients' => $ingredients >= 2 ? null : 'Нужно хотя бы два ингредиента.',
            'steps' => $steps >= 2 ? null : 'Нужно хотя бы два шага.',
            'time_minutes' => $row['time_minutes'] ? null : 'Укажите время приготовления.',
        ]);
        if ([] !== $missing) {
            throw new ValidationFailed(array_map(static fn (string $m): array => [$m], $missing));
        }

        $this->move($row, 'moderation', 'recipe.submitted', $user);

        return new JsonResponse(['data' => $this->rowOf($recipe)]);
    }

    /** Забрать с модерации, чтобы поправить. */
    #[Route('/me/recipes/{recipe}/withdraw', name: 'my_recipe_withdraw', requirements: ['recipe' => Requirement::DIGITS], methods: ['POST'])]
    public function withdraw(int $recipe, #[CurrentUser] User $user): JsonResponse
    {
        $row = $this->own($recipe, $user);
        if ('moderation' !== $row['status']) {
            throw new HttpException(422, 'Забрать можно только рецепт на модерации.');
        }

        $this->move($row, 'draft', 'recipe.withdrawn', $user);

        return new JsonResponse(['data' => $this->rowOf($recipe)]);
    }

    #[Route('/me/recipes/{recipe}', name: 'my_recipe_destroy', requirements: ['recipe' => Requirement::DIGITS], methods: ['DELETE'])]
    public function destroy(int $recipe, #[CurrentUser] User $user): JsonResponse
    {
        $this->editable($this->own($recipe, $user));
        $this->db->delete('recipes', ['id' => $recipe]);

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    /** Фото для рецепта: обложка, галерея, шаги. Только изображения до 8 МБ. */
    #[Route('/me/recipes/media', name: 'my_recipe_upload', methods: ['POST'], priority: 1)]
    #[RouteThrottle(limit: 60, minutes: 60)]
    public function upload(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data = $this->validator->validate($request, [
            'file' => 'required|image|mimes:jpeg,jpg,png,webp|max:8192',
        ], [
            'file.image' => 'Подойдёт фотография: JPG, PNG или WebP.',
            'file.max' => 'Фото должно быть меньше 8 МБ.',
        ]);
        \assert($data['file'] instanceof UploadedFile);

        $media = $this->media->store($data['file'], $user->getId(), 'Фото рецепта читателя');

        return new JsonResponse(['data' => ['id' => $media['id'], 'url' => $media['url']]], 201);
    }

    /**
     * Чужой рецепт — 404: незачем подтверждать, что он существует.
     *
     * @return array<string, mixed>
     */
    private function own(int $id, User $user): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM recipes WHERE id = ?', [$id]);
        if (false === $row || (int) $row['author_id'] !== $user->getId()) {
            throw new NotFoundHttpException();
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function editable(array $row): void
    {
        if (!\in_array($row['status'], ['draft', 'rejected'], true)) {
            throw new NotEditable('moderation' === $row['status'] ? 'Рецепт на модерации. Чтобы поправить, сначала заберите его с проверки.' : 'Опубликованный рецепт может изменить только редакция.');
        }
    }

    /** @param array<string, mixed> $row */
    private function move(array $row, string $status, string $action, User $user): void
    {
        $this->db->update('recipes', ['status' => $status, 'updated_at' => PublishedRecipes::now($this->clock->now())], ['id' => $row['id']]);
        $this->log->record($user->getId(), $action, 'Recipe', (int) $row['id'], ['title' => $row['title']]);
    }

    /**
     * RecipeResource (без автора и счётчиков — они не загружены) + строка кабинета.
     * Совпадающие ключи берутся из полного представления рецепта, как оператор + у массивов.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function detail(array $row): array
    {
        $covers = $this->presenter->mediaByIds([$row['cover_media_id']]);

        return $this->presenter->full($row, ['my_reaction' => null], withAuthor: false) + $this->row($row, $covers);
    }

    /** @return array<string, mixed> */
    private function rowOf(int $id): array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM recipes WHERE id = ?', [$id]);
        \assert(false !== $row);

        return $this->row($row, $this->presenter->mediaByIds([$row['cover_media_id']]));
    }

    /**
     * @param array<string, mixed>             $r
     * @param array<int, array<string, mixed>> $covers
     *
     * @return array<string, mixed>
     */
    private function row(array $r, array $covers): array
    {
        $cover = $covers[(int) $r['cover_media_id']] ?? null;

        return [
            'id' => (int) $r['id'],
            'slug' => $r['slug'],
            'title' => $r['title'],
            'status' => $r['status'],
            'status_label' => self::STATUS_LABELS[$r['status']] ?? $r['status'],
            'moderation_note' => 'rejected' === $r['status'] ? $r['moderation_note'] : null,
            'can_edit' => \in_array($r['status'], ['draft', 'rejected'], true),
            'cover' => null === $cover ? null : ['url' => $cover['url'], 'alt' => $cover['alt']],
            'updated_at' => RecipePresenter::iso($r['updated_at']),
            'published_at' => RecipePresenter::iso($r['published_at']),
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        $fields = array_intersect_key($data, array_flip(self::FIELDS));
        foreach (['is_vegetarian', 'is_gluten_free'] as $flag) {
            if (\array_key_exists($flag, $fields) && null !== $fields[$flag]) {
                $fields[$flag] = (int) filter_var($fields[$flag], \FILTER_VALIDATE_BOOL);
            }
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, User $user, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        // Фото — только свои: id чужих снимков из медиатеки не принимаются.
        $ownMedia = 'exists:media,id,uploaded_by,'.$user->getId();

        return $this->validator->validate($request, [
            'title' => $required.'|string|min:3|max:200',
            'lead' => 'nullable|string|max:600',
            'category_id' => 'nullable|integer|exists:categories,id',
            'cover_media_id' => 'nullable|integer|'.$ownMedia,
            'time_minutes' => 'nullable|integer|min:1|max:1440',
            'servings' => 'nullable|integer|min:1|max:50',
            'difficulty' => 'nullable|in:easy,medium,hard',
            'calories' => 'nullable|integer|min:0|max:5000',
            'protein' => 'nullable|integer|min:0|max:500',
            'fat' => 'nullable|integer|min:0|max:500',
            'carbs' => 'nullable|integer|min:0|max:500',
            'is_vegetarian' => 'nullable|boolean',
            'is_gluten_free' => 'nullable|boolean',
            'ingredients' => 'nullable|array|max:60',
            'ingredients.*.name' => 'required|string|max:160',
            'ingredients.*.amount' => 'nullable|numeric|min:0|max:100000',
            'ingredients.*.unit' => 'nullable|string|max:32',
            'steps' => 'nullable|array|max:40',
            'steps.*.body' => 'required|string|max:2000',
            'steps.*.hint' => 'nullable|string|max:160',
            'steps.*.tip' => 'nullable|string|max:1000',
            'steps.*.substitution' => 'nullable|string|max:1000',
            'steps.*.media_id' => 'nullable|integer|'.$ownMedia,
            'tags' => 'nullable|array|max:10',
            'tags.*' => 'string|max:60',
            'gallery' => 'nullable|array|max:8',
            'gallery.*.media_id' => 'required|integer|'.$ownMedia,
            'gallery.*.caption' => 'nullable|string|max:160',
        ], [
            'title.required' => 'Назовите рецепт.',
            'ingredients.*.name.required' => 'У каждого ингредиента должно быть название.',
            'steps.*.body.required' => 'В каждом шаге должен быть текст.',
            'cover_media_id.exists' => 'Фото не найдено — загрузите его заново.',
            'steps.*.media_id.exists' => 'Фото шага не найдено — загрузите его заново.',
            'gallery.*.media_id.exists' => 'Фото галереи не найдено — загрузите его заново.',
        ]);
    }
}
