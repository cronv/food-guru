<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ReviewResource;
use App\Models\ActivityLog;
use App\Models\Recipe;
use App\Models\Review;
use App\Services\MediaService;
use App\Services\RatingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ReviewController
{
    public function __construct(
        private MediaService $media,
        private RatingService $rating,
    ) {}

    public function index(string $slug): AnonymousResourceCollection
    {
        $recipe = Recipe::published()->where('slug', $slug)->firstOrFail();

        $reviews = $recipe->reviews()
            ->visible()
            ->with(['author', 'media'])
            ->latest()
            ->paginate(10);

        return ReviewResource::collection($reviews);
    }

    public function store(Request $request, string $slug): JsonResponse
    {
        $recipe = Recipe::published()->where('slug', $slug)->firstOrFail();
        $user = $request->user();

        if ($recipe->reviews()->where('user_id', $user->id)->exists()) {
            return response()->json([
                'message' => 'Вы уже оставляли отзыв к этому рецепту.',
                'code' => 'review_exists',
            ], 409);
        }

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'body' => ['required', 'string', 'min:10', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:3'],
            'photos.*' => ['image', 'mimes:jpeg,jpg,png,webp', 'max:8192'],
        ], [
            'rating.required' => 'Поставьте оценку от 1 до 5.',
            'body.min' => 'Напишите пару предложений — так отзыв полезнее.',
            'photos.max' => 'К отзыву можно приложить не больше трёх снимков.',
            'photos.*.max' => 'Каждый снимок должен быть меньше 8 МБ.',
        ]);

        $review = DB::transaction(function () use ($data, $recipe, $user, $request) {
            $review = Review::create([
                'recipe_id' => $recipe->id,
                'user_id' => $user->id,
                'rating' => $data['rating'],
                'body' => $data['body'],
                'status' => 'published',
            ]);

            foreach ($request->file('photos', []) as $i => $file) {
                $media = $this->media->store($file, $user, $recipe->title.' — фото читателя');
                $review->media()->attach($media->id, ['position' => $i]);
            }

            ActivityLog::record($user, 'review.created', $review, ['recipe' => $recipe->slug]);

            /*
             * Баллы за отзыв и, если приложены снимки, за фотоотчёт.
             * Ключ начисления привязан к паре «человек + рецепт», поэтому
             * удалить отзыв и написать заново ради баллов не выйдет.
             */
            $this->rating->awardForReview($review);

            return $review;
        });

        return response()->json([
            'data' => new ReviewResource($review->load(['author', 'media'])),
        ], 201);
    }

    public function destroy(Request $request, Review $review): JsonResponse
    {
        // Свой отзыв можно удалить; чужие — только с правами редакции.
        abort_unless(
            $review->user_id === $request->user()->id || $request->user()->isStaff(),
            403
        );

        $review->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }
}
