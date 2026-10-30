<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\RecipeStatus;
use App\Enums\UserRole;
use App\Http\Resources\RecipeCardResource;
use App\Http\Resources\RecipeResource;
use App\Mail\RecipeModeratedMail;
use App\Models\ActivityLog;
use App\Models\Recipe;
use App\Services\RatingService;
use App\Services\RecipeWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminRecipeController
{
    public function __construct(
        private RatingService $rating,
        private RecipeWriter $writer,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:draft,moderation,published,rejected,archived'],
            'q' => ['nullable', 'string', 'max:120'],
            'author' => ['nullable', 'integer', 'exists:users,id'],
            'category' => ['nullable', 'string', 'exists:categories,slug'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Recipe::query()
            ->with(['cover', 'category', 'author'])
            ->latest('updated_at');

        // Контент-менеджер видит в админке только свои материалы.
        if (! $request->user()->atLeast(UserRole::EditorLead)) {
            $query->where('author_id', $request->user()->id);
        }

        // Черновик читателя — его личное, пока он не отправил рецепт на модерацию.
        $query->where(fn ($q) => $q->where('status', '!=', RecipeStatus::Draft)
            ->orWhereHas('author', fn ($a) => $a->where('role', '!=', UserRole::User)));

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['q'])) {
            $query->where('title', 'like', '%'.$data['q'].'%');
        }
        if (! empty($data['author'])) {
            $query->where('author_id', $data['author']);
        }
        if (! empty($data['category'])) {
            $query->whereHas('category', fn ($q) => $q->where('slug', $data['category']));
        }

        return RecipeCardResource::collection($query->paginate($data['per_page'] ?? 20)->withQueryString());
    }

    public function show(Request $request, Recipe $recipe): RecipeResource
    {
        $this->authorizeView($request, $recipe);

        return new RecipeResource(
            $recipe->load(['cover', 'gallery', 'category', 'author', 'tags', 'ingredients', 'steps.media'])
        );
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('create', Recipe::class), 403);

        $data = $this->validateRecipe($request, creating: true);

        $recipe = DB::transaction(function () use ($data, $request) {
            $recipe = Recipe::create([
                'author_id' => $request->user()->id,
                'category_id' => $data['category_id'] ?? null,
                'cover_media_id' => $data['cover_media_id'] ?? null,
                'slug' => $this->uniqueSlug($data['slug'] ?? $data['title']),
                'title' => $data['title'],
                'lead' => $data['lead'] ?? null,
                'time_minutes' => $data['time_minutes'] ?? null,
                'servings' => $data['servings'] ?? 4,
                'difficulty' => $data['difficulty'] ?? 'easy',
                'status' => RecipeStatus::Draft,
                'og_description' => $data['og_description'] ?? null,
                'calories' => $data['calories'] ?? null,
                'protein' => $data['protein'] ?? null,
                'fat' => $data['fat'] ?? null,
                'carbs' => $data['carbs'] ?? null,
                'is_vegetarian' => $data['is_vegetarian'] ?? false,
                'is_gluten_free' => $data['is_gluten_free'] ?? false,
            ]);

            $this->syncChildren($recipe, $data);
            ActivityLog::record($request->user(), 'recipe.created', $recipe, ['title' => $recipe->title]);

            return $recipe;
        });

        return response()->json([
            'data' => new RecipeResource($recipe->load(['ingredients', 'steps', 'tags', 'cover'])),
        ], 201);
    }

    public function update(Request $request, Recipe $recipe): JsonResponse
    {
        abort_unless($request->user()->can('update', $recipe), 403);

        $data = $this->validateRecipe($request, creating: false);

        DB::transaction(function () use ($data, $recipe, $request) {
            $recipe->fill(array_filter([
                'category_id' => $data['category_id'] ?? null,
                'cover_media_id' => $data['cover_media_id'] ?? null,
                'title' => $data['title'] ?? null,
                'lead' => $data['lead'] ?? null,
                'time_minutes' => $data['time_minutes'] ?? null,
                'servings' => $data['servings'] ?? null,
                'difficulty' => $data['difficulty'] ?? null,
                'og_description' => $data['og_description'] ?? null,
                'calories' => $data['calories'] ?? null,
                'protein' => $data['protein'] ?? null,
                'fat' => $data['fat'] ?? null,
                'carbs' => $data['carbs'] ?? null,
            ], fn ($v) => $v !== null));

            if (array_key_exists('is_vegetarian', $data)) {
                $recipe->is_vegetarian = $data['is_vegetarian'];
            }
            if (array_key_exists('is_gluten_free', $data)) {
                $recipe->is_gluten_free = $data['is_gluten_free'];
            }
            if (! empty($data['slug']) && $data['slug'] !== $recipe->slug) {
                $recipe->slug = $this->uniqueSlug($data['slug'], $recipe->id);
            }

            $recipe->save();
            $this->syncChildren($recipe, $data);

            ActivityLog::record($request->user(), 'recipe.updated', $recipe, ['title' => $recipe->title]);
        });

        return response()->json([
            'data' => new RecipeResource($recipe->fresh()->load(['ingredients', 'steps', 'tags', 'cover', 'gallery'])),
        ]);
    }

    /** Загрузить видеорецепт (рилс). Прежний файл удаляется. */
    public function uploadReel(Request $request, Recipe $recipe): JsonResponse
    {
        abort_unless($request->user()->can('update', $recipe), 403);

        $request->validate([
            // Тип определяется по содержимому, а не по имени: см. MediaService.
            'file' => ['required', 'file', 'mimetypes:video/mp4,video/webm', 'max:81920'],
        ], [
            'file.required' => 'Выберите видеофайл.',
            'file.mimetypes' => 'Подойдёт видео в формате MP4 или WebM.',
            'file.max' => 'Видео не должно весить больше 80 МБ.',
        ]);

        $file = $request->file('file');
        // Расширение выбирается из двух допустимых по типу содержимого, не из имени.
        $extension = $file->getMimeType() === 'video/webm' ? 'webm' : 'mp4';
        $path = $file->storeAs('reels', Str::uuid().'.'.$extension, 'public');

        $this->deleteReelFile($recipe);
        $recipe->forceFill(['reel_path' => $path])->save();

        ActivityLog::record($request->user(), 'recipe.reel_uploaded', $recipe, ['title' => $recipe->title]);

        return response()->json(['data' => ['reel_url' => $recipe->reelUrl()]]);
    }

    public function deleteReel(Request $request, Recipe $recipe): JsonResponse
    {
        abort_unless($request->user()->can('update', $recipe), 403);

        $this->deleteReelFile($recipe);
        $recipe->forceFill(['reel_path' => null])->save();

        ActivityLog::record($request->user(), 'recipe.reel_deleted', $recipe, ['title' => $recipe->title]);

        return response()->json(['data' => ['reel_url' => null]]);
    }

    private function deleteReelFile(Recipe $recipe): void
    {
        // Только файлы, загруженные через админку: демо-ролики из сидов лежат
        // в том же каталоге, но их имена не UUID, и трогать их незачем.
        if ($recipe->reel_path && preg_match('#^reels/[0-9a-f-]{36}\.#', $recipe->reel_path)) {
            Storage::disk('public')->delete($recipe->reel_path);
        }
    }

    /** Отправить на модерацию — доступно автору. */
    public function submit(Request $request, Recipe $recipe): JsonResponse
    {
        abort_unless($request->user()->can('submit', $recipe), 403);

        return $this->move($request, $recipe, RecipeStatus::Moderation, 'recipe.submitted');
    }

    public function publish(Request $request, Recipe $recipe): JsonResponse
    {
        abort_unless($request->user()->can('publish', $recipe), 403);

        $response = $this->move($request, $recipe, RecipeStatus::Published, 'recipe.published');

        if ($recipe->fresh()->status === RecipeStatus::Published && ! $recipe->published_at) {
            $recipe->forceFill(['published_at' => now(), 'moderation_note' => null])->save();
        }

        // Баллы автору. Повторная публикация того же рецепта ничего не добавит.
        if ($recipe->fresh()->status === RecipeStatus::Published) {
            $this->rating->awardForRecipe($recipe->loadMissing('author'));
            $this->notifyReader($recipe->fresh(), published: true);
        }

        return $response;
    }

    public function reject(Request $request, Recipe $recipe): JsonResponse
    {
        abort_unless($request->user()->can('reject', $recipe), 403);

        $data = $request->validate([
            'note' => ['required', 'string', 'min:5', 'max:1000'],
        ], ['note.required' => 'Напишите, что именно нужно поправить.']);

        $recipe->moderation_note = $data['note'];
        $recipe->save();

        $response = $this->move($request, $recipe, RecipeStatus::Rejected, 'recipe.rejected', ['note' => $data['note']]);

        if ($recipe->fresh()->status === RecipeStatus::Rejected) {
            $this->notifyReader($recipe->fresh(), published: false);
        }

        return $response;
    }

    /**
     * Письмо автору-читателю о решении редакции. Сотрудникам не пишем:
     * они и так видят статус в админке.
     */
    private function notifyReader(Recipe $recipe, bool $published): void
    {
        $author = $recipe->author;

        // Автор есть всегда: recipes.author_id — внешний ключ с каскадным удалением.
        if ($author->role === UserRole::User) {
            Mail::to($author->email)->queue(new RecipeModeratedMail($author, $recipe, $published));
        }
    }

    public function archive(Request $request, Recipe $recipe): JsonResponse
    {
        abort_unless($request->user()->can('archive', $recipe), 403);

        return $this->move($request, $recipe, RecipeStatus::Archived, 'recipe.archived');
    }

    /** Общий переход статуса с проверкой допустимости. */
    private function move(Request $request, Recipe $recipe, RecipeStatus $to, string $action, array $payload = []): JsonResponse
    {
        if (! $recipe->status->canMoveTo($to)) {
            return response()->json([
                'message' => "Из статуса «{$recipe->status->label()}» нельзя перейти в «{$to->label()}».",
                'code' => 'invalid_transition',
            ], 422);
        }

        $from = $recipe->status;
        $recipe->status = $to;
        if ($to === RecipeStatus::Published && ! $recipe->published_at) {
            $recipe->published_at = now();
        }
        $recipe->save();

        ActivityLog::record($request->user(), $action, $recipe, array_merge([
            'from' => $from->value,
            'to' => $to->value,
            'title' => $recipe->title,
        ], $payload));

        return response()->json([
            'data' => ['status' => $to->value, 'status_label' => $to->label()],
        ]);
    }

    private function authorizeView(Request $request, Recipe $recipe): void
    {
        abort_unless($request->user()->can('view', $recipe), 403);
    }

    private function validateRecipe(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'title' => [$required, 'string', 'min:3', 'max:200'],
            'slug' => ['nullable', 'string', 'max:200', 'regex:/^[a-z0-9-]+$/'],
            'lead' => ['nullable', 'string', 'max:600'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'cover_media_id' => ['nullable', 'integer', 'exists:media,id'],
            'time_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'servings' => ['nullable', 'integer', 'min:1', 'max:50'],
            'difficulty' => ['nullable', 'in:easy,medium,hard'],
            'og_description' => ['nullable', 'string', 'max:300'],
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
            'steps.*.chef_secret' => ['nullable', 'string', 'max:1000'],
            'steps.*.media_id' => ['nullable', 'integer', 'exists:media,id'],

            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:60'],

            'gallery' => ['nullable', 'array', 'max:12'],
            'gallery.*.media_id' => ['required', 'integer', 'exists:media,id'],
            'gallery.*.caption' => ['nullable', 'string', 'max:160'],
        ], [
            'slug.regex' => 'В адресе допустимы только латинские буквы, цифры и дефис.',
        ]);
    }

    private function syncChildren(Recipe $recipe, array $data): void
    {
        $this->writer->syncChildren($recipe, $data);
    }

    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        return $this->writer->uniqueSlug($source, $ignoreId);
    }
}
