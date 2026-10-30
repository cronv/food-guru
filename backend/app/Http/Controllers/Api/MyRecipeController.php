<?php

namespace App\Http\Controllers\Api;

use App\Enums\RecipeStatus;
use App\Http\Resources\RecipeResource;
use App\Models\ActivityLog;
use App\Models\Recipe;
use App\Services\MediaService;
use App\Services\RecipeWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Рецепты читателя: черновик → на модерацию → редакция публикует или возвращает.
 *
 * Отличия от админки: читатель правит только свои черновики и возвращённые
 * рецепты; фото — только свои; без адреса, SEO-описания и «секретов шефа»;
 * опубликованное не правит — иначе можно было бы обойти модерацию.
 */
class MyRecipeController
{
    /** Сколько рецептов одновременно может быть в работе — защита от потока черновиков. */
    private const OPEN_LIMIT = 20;

    public function __construct(
        private RecipeWriter $writer,
        private MediaService $media,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $recipes = Recipe::where('author_id', $request->user()->id)
            ->with('cover')
            ->latest('updated_at')
            ->get();

        return response()->json(['data' => $recipes->map(fn (Recipe $r) => $this->row($r))]);
    }

    public function show(Request $request, Recipe $recipe): JsonResponse
    {
        $this->own($request, $recipe);

        $recipe->load(['cover', 'gallery', 'category', 'tags', 'ingredients', 'steps.media']);

        return response()->json([
            'data' => (new RecipeResource($recipe))->resolve($request) + $this->row($recipe),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $open = Recipe::where('author_id', $user->id)
            ->whereIn('status', [RecipeStatus::Draft, RecipeStatus::Moderation, RecipeStatus::Rejected])
            ->count();
        if ($open >= self::OPEN_LIMIT) {
            return response()->json(['message' => 'У вас уже '.self::OPEN_LIMIT.' рецептов в работе. Отправьте или удалите часть черновиков.'], 422);
        }

        $data = $this->validated($request, creating: true);

        $recipe = DB::transaction(function () use ($data, $user) {
            $recipe = Recipe::create($this->fields($data) + [
                'author_id' => $user->id,
                'slug' => $this->writer->uniqueSlug($data['title']),
                'status' => RecipeStatus::Draft,
                'servings' => $data['servings'] ?? 4,
                'difficulty' => $data['difficulty'] ?? 'easy',
            ]);
            $this->writer->syncChildren($recipe, $data);
            ActivityLog::record($user, 'recipe.created', $recipe, ['title' => $recipe->title]);

            return $recipe;
        });

        return $this->show($request, $recipe->fresh())->setStatusCode(201);
    }

    public function update(Request $request, Recipe $recipe): JsonResponse
    {
        $this->own($request, $recipe);
        $this->editable($recipe);

        $data = $this->validated($request, creating: false);

        DB::transaction(function () use ($data, $recipe, $request) {
            $recipe->fill($this->fields($data));
            // Адрес следует за названием, пока рецепт не опубликован.
            if (isset($data['title'])) {
                $recipe->slug = $this->writer->uniqueSlug($data['title'], $recipe->id);
            }
            $recipe->save();
            $this->writer->syncChildren($recipe, $data);
            ActivityLog::record($request->user(), 'recipe.updated', $recipe, ['title' => $recipe->title]);
        });

        return $this->show($request, $recipe->fresh());
    }

    /** Отправить на модерацию. Перед этим — проверка полноты: пустое редакции не нужно. */
    public function submit(Request $request, Recipe $recipe): JsonResponse
    {
        $this->own($request, $recipe);
        $this->editable($recipe);

        $recipe->loadCount(['ingredients', 'steps']);
        $missing = array_filter([
            'cover_media_id' => $recipe->cover_media_id ? null : 'Добавьте фото готового блюда.',
            'lead' => mb_strlen((string) $recipe->lead) >= 20 ? null : 'Напишите пару предложений о блюде — от 20 символов.',
            'ingredients' => $recipe->ingredients_count >= 2 ? null : 'Нужно хотя бы два ингредиента.',
            'steps' => $recipe->steps_count >= 2 ? null : 'Нужно хотя бы два шага.',
            'time_minutes' => $recipe->time_minutes ? null : 'Укажите время приготовления.',
        ]);
        if ($missing) {
            throw ValidationException::withMessages($missing);
        }

        $recipe->forceFill(['status' => RecipeStatus::Moderation])->save();
        ActivityLog::record($request->user(), 'recipe.submitted', $recipe, ['title' => $recipe->title]);

        return response()->json(['data' => $this->row($recipe->fresh('cover'))]);
    }

    /** Забрать с модерации, чтобы поправить. */
    public function withdraw(Request $request, Recipe $recipe): JsonResponse
    {
        $this->own($request, $recipe);
        abort_unless($recipe->status === RecipeStatus::Moderation, 422, 'Забрать можно только рецепт на модерации.');

        $recipe->forceFill(['status' => RecipeStatus::Draft])->save();
        ActivityLog::record($request->user(), 'recipe.withdrawn', $recipe, ['title' => $recipe->title]);

        return response()->json(['data' => $this->row($recipe->fresh('cover'))]);
    }

    public function destroy(Request $request, Recipe $recipe): JsonResponse
    {
        $this->own($request, $recipe);
        $this->editable($recipe);

        $recipe->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** Фото для рецепта: обложка, галерея, шаги. Только изображения до 8 МБ. */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'],
        ], [
            'file.image' => 'Подойдёт фотография: JPG, PNG или WebP.',
            'file.max' => 'Фото должно быть меньше 8 МБ.',
        ]);

        $media = $this->media->store($request->file('file'), $request->user(), 'Фото рецепта читателя');

        return response()->json(['data' => ['id' => $media->id, 'url' => $media->url()]], 201);
    }

    /** Чужой рецепт — 404: незачем подтверждать, что он существует. */
    private function own(Request $request, Recipe $recipe): void
    {
        abort_unless($recipe->author_id === $request->user()->id, 404);
    }

    private function editable(Recipe $recipe): void
    {
        if (! in_array($recipe->status, [RecipeStatus::Draft, RecipeStatus::Rejected], true)) {
            abort(response()->json([
                'message' => $recipe->status === RecipeStatus::Moderation
                    ? 'Рецепт на модерации. Чтобы поправить, сначала заберите его с проверки.'
                    : 'Опубликованный рецепт может изменить только редакция.',
                'code' => 'not_editable',
            ], 422));
        }
    }

    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        // Фото — только свои: id чужих снимков из медиатеки не принимаются.
        $ownMedia = Rule::exists('media', 'id')->where('uploaded_by', $request->user()->id);

        return $request->validate([
            'title' => [$required, 'string', 'min:3', 'max:200'],
            'lead' => ['nullable', 'string', 'max:600'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'cover_media_id' => ['nullable', 'integer', $ownMedia],
            'time_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'servings' => ['nullable', 'integer', 'min:1', 'max:50'],
            'difficulty' => ['nullable', 'in:easy,medium,hard'],
            'calories' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'protein' => ['nullable', 'integer', 'min:0', 'max:500'],
            'fat' => ['nullable', 'integer', 'min:0', 'max:500'],
            'carbs' => ['nullable', 'integer', 'min:0', 'max:500'],
            'is_vegetarian' => ['nullable', 'boolean'],
            'is_gluten_free' => ['nullable', 'boolean'],

            'ingredients' => ['nullable', 'array', 'max:60'],
            'ingredients.*.name' => ['required', 'string', 'max:160'],
            'ingredients.*.amount' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'ingredients.*.unit' => ['nullable', 'string', 'max:32'],

            'steps' => ['nullable', 'array', 'max:40'],
            'steps.*.body' => ['required', 'string', 'max:2000'],
            'steps.*.hint' => ['nullable', 'string', 'max:160'],
            'steps.*.tip' => ['nullable', 'string', 'max:1000'],
            'steps.*.substitution' => ['nullable', 'string', 'max:1000'],
            'steps.*.media_id' => ['nullable', 'integer', $ownMedia],

            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:60'],

            'gallery' => ['nullable', 'array', 'max:8'],
            'gallery.*.media_id' => ['required', 'integer', $ownMedia],
            'gallery.*.caption' => ['nullable', 'string', 'max:160'],
        ], [
            'title.required' => 'Назовите рецепт.',
            'ingredients.*.name.required' => 'У каждого ингредиента должно быть название.',
            'steps.*.body.required' => 'В каждом шаге должен быть текст.',
            'cover_media_id.exists' => 'Фото не найдено — загрузите его заново.',
            'steps.*.media_id.exists' => 'Фото шага не найдено — загрузите его заново.',
            'gallery.*.media_id.exists' => 'Фото галереи не найдено — загрузите его заново.',
        ]);
    }

    /** Простые поля рецепта из запроса; отсутствующие ключи не трогаются. */
    private function fields(array $data): array
    {
        return collect($data)->only([
            'title', 'lead', 'category_id', 'cover_media_id', 'time_minutes', 'servings', 'difficulty',
            'calories', 'protein', 'fat', 'carbs', 'is_vegetarian', 'is_gluten_free',
        ])->all();
    }

    private function row(Recipe $r): array
    {
        return [
            'id' => $r->id,
            'slug' => $r->slug,
            'title' => $r->title,
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            'moderation_note' => $r->status === RecipeStatus::Rejected ? $r->moderation_note : null,
            'can_edit' => in_array($r->status, [RecipeStatus::Draft, RecipeStatus::Rejected], true),
            'cover' => $r->cover ? ['url' => $r->cover->url(), 'alt' => $r->cover->alt] : null,
            'updated_at' => $r->updated_at?->toIso8601String(),
            'published_at' => $r->published_at?->toIso8601String(),
        ];
    }
}
