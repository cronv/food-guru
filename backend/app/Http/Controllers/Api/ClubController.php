<?php

namespace App\Http\Controllers\Api;

use App\Models\Book;
use App\Models\ClubItem;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Commerce\Access;
use App\Services\Commerce\Checkout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Закрытая академия. Каталог и тарифы видны всем — чтобы было понятно,
 * за что платить. Содержимое, видео и книги из подписки — только подписчикам.
 */
class ClubController
{
    public function __construct(private Access $access) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['kind' => ['nullable', Rule::in(array_keys(ClubItem::KINDS))]]);
        $user = $request->user('sanctum');
        $unlocked = $this->access->hasSubscription($user);

        $items = ClubItem::published()
            ->with('cover')
            ->when($data['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k))
            ->orderByDesc('published_at')
            ->get();

        $books = Book::published()->where('in_subscription', true)->with('cover')->orderBy('title')->get();

        return response()->json([
            'data' => $items->map(fn (ClubItem $i) => $i->toCard($unlocked)),
            'meta' => [
                'kinds' => collect(ClubItem::KINDS)->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values(),
                'plans' => SubscriptionPlan::where('is_active', true)->orderBy('position')->get()->map->toApi(),
                'subscription' => $this->subscriptionState($user),
                'books' => $books->map(fn (Book $b) => [
                    'slug' => $b->slug,
                    'title' => $b->title,
                    'author_name' => $b->author_name,
                    'cover' => $b->cover ? ['url' => $b->cover->url(), 'alt' => $b->cover->alt] : null,
                ]),
            ],
        ]);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $user = $request->user('sanctum');
        $item = ClubItem::published()->where('slug', $slug)->with('cover')->firstOrFail();
        $unlocked = $this->access->hasSubscription($user);

        $card = $item->toCard($unlocked);

        if (! $unlocked) {
            // Без подписки — только обложка и описание: тело, видео и рецепт не отдаём.
            return response()->json(['data' => $card, 'meta' => ['subscription' => $this->subscriptionState($user)]]);
        }

        return response()->json([
            'data' => $card + [
                'body' => $item->body,
                'recipe' => $item->kind === 'recipe' ? $item->data : null,
                // Ссылка на видео живёт 2 часа: ею нельзя поделиться надолго.
                'video_url' => $item->hasVideo()
                    ? URL::temporarySignedRoute('club.video', now()->addHours(2), ['item' => $item->id, 'user' => $user->id])
                    : null,
            ],
            'meta' => ['subscription' => $this->subscriptionState($user)],
        ]);
    }

    /**
     * Видео по подписанной ссылке. Подпись проверяет middleware `signed`,
     * а подписку — ещё раз здесь: ссылка не должна пережить конец подписки.
     */
    public function video(Request $request, ClubItem $item): BinaryFileResponse
    {
        $user = User::find($request->query('user'));
        abort_unless($item->hasVideo() && $this->access->hasSubscription($user), 403);

        $path = Storage::disk($item->video_disk)->path($item->video_path);
        abort_unless(is_file($path), 404);

        // BinaryFileResponse отдаёт Range-запросы — видео можно перематывать.
        return response()->file($path, ['Content-Type' => 'video/mp4', 'Cache-Control' => 'private, max-age=0']);
    }

    public function subscribe(Request $request, Checkout $checkout): JsonResponse
    {
        $data = $request->validate(['plan' => ['required', 'string']]);
        $plan = SubscriptionPlan::where('code', $data['plan'])->where('is_active', true)->firstOrFail();

        $checkout->subscribe($request->user(), $plan);

        return response()->json(['data' => $this->subscriptionState($request->user())], 201);
    }

    public function mySubscription(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->subscriptionState($request->user())]);
    }

    private function subscriptionState($user): array
    {
        $ends = $this->access->subscriptionEndsAt($user);

        return [
            'active' => $ends !== null,
            'ends_at' => $ends?->toIso8601String(),
        ];
    }
}
