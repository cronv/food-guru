<?php

namespace App\Http\Controllers\Api;

use App\Models\Book;
use App\Models\User;
use App\Services\Commerce\Access;
use App\Services\Commerce\BookPdf;
use App\Services\Commerce\CatalogFilters;
use App\Services\Commerce\Checkout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class BookController
{
    public function __construct(private Access $access) {}

    /** Каталог книг: поиск, статус, цена (копейки), год, автор, страна, сортировка. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            // filter — прежнее имя параметра, status — новое; значат одно и то же.
            'filter' => ['nullable', 'in:all,free,paid,subscription,mine'],
            'status' => ['nullable', 'in:all,free,paid,subscription,mine'],
            'price_min' => ['nullable', 'integer', 'min:0'],
            'price_max' => ['nullable', 'integer', 'min:0'],
            'year_from' => ['nullable', 'integer', 'between:1800,2100'],
            'year_to' => ['nullable', 'integer', 'between:1800,2100'],
            'author' => ['nullable', 'array', 'max:20'], 'author.*' => ['string', 'max:160'],
            'country' => ['nullable', 'array', 'max:20'], 'country.*' => ['string', 'max:80'],
            'sort' => ['nullable', 'in:new,price_asc,price_desc,title,year_desc,year_asc'],
        ]);
        $user = $request->user('sanctum');
        $status = $data['status'] ?? $data['filter'] ?? 'all';

        $query = Book::published()->with('cover')->withCount('chapters')
            ->when($status === 'free', fn ($q) => $q->where('price', 0))
            ->when($status === 'paid', fn ($q) => $q->where('price', '>', 0))
            ->when($status === 'subscription', fn ($q) => $q->where('in_subscription', true))
            ->when($status === 'mine', fn ($q) => $q->whereHas('purchases', fn ($p) => $p->where('user_id', $user->id ?? 0)));

        CatalogFilters::search($query, $data['q'] ?? null);
        CatalogFilters::price($query, $data['price_min'] ?? null, $data['price_max'] ?? null);
        CatalogFilters::in($query, 'author_name', $data['author'] ?? null);
        CatalogFilters::in($query, 'country', $data['country'] ?? null);
        if (isset($data['year_from'])) {
            $query->where('year', '>=', $data['year_from']);
        }
        if (isset($data['year_to'])) {
            $query->where('year', '<=', $data['year_to']);
        }

        match ($data['sort'] ?? 'new') {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'title' => $query->orderBy('title'),
            // Книги без года — в конце в обоих направлениях.
            'year_desc' => $query->orderByRaw('year IS NULL')->orderByDesc('year'),
            'year_asc' => $query->orderByRaw('year IS NULL')->orderBy('year'),
            default => $query->orderByDesc('published_at'),
        };

        $all = Book::published();

        return response()->json([
            'data' => $query->get()->map(fn (Book $b) => $this->card($b, $user)),
            'meta' => [
                'total' => Book::published()->count(),
                'facets' => [
                    'authors' => CatalogFilters::facet($all, 'author_name'),
                    'countries' => CatalogFilters::facet($all, 'country'),
                    'years' => [
                        'min' => (int) (clone $all)->min('year') ?: null,
                        'max' => (int) (clone $all)->max('year') ?: null,
                    ],
                    'price' => CatalogFilters::priceRange($all),
                ],
            ],
        ]);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $user = $request->user('sanctum');
        $book = Book::published()->where('slug', $slug)->with(['cover', 'chapters'])->firstOrFail();

        return response()->json(['data' => $this->card($book, $user) + [
            'description' => $book->description,
            'chapters' => $book->chapters->map(fn ($c) => [
                'position' => $c->position,
                'title' => $c->title,
                'is_preview' => $c->is_preview,
                'readable' => $this->access->canReadChapter($user, $book, $c),
            ]),
        ]]);
    }

    /** Глава для читалки. Закрытая — 403 с понятной причиной, а не 404. */
    public function chapter(Request $request, string $slug, int $position): JsonResponse
    {
        $user = $request->user('sanctum');
        $book = Book::published()->where('slug', $slug)->with('chapters')->firstOrFail();
        $chapter = $book->chapters->firstWhere('position', $position) ?? abort(404);

        if (! $this->access->canReadChapter($user, $book, $chapter)) {
            return response()->json([
                'message' => $book->in_subscription
                    ? 'Глава доступна после покупки книги или по подписке на закрытую академию.'
                    : 'Глава доступна после покупки книги.',
                'code' => 'locked',
            ], 403);
        }

        $positions = $book->chapters->pluck('position');
        $index = $positions->search($position);

        return response()->json(['data' => [
            'book' => ['slug' => $book->slug, 'title' => $book->title],
            'position' => $chapter->position,
            'title' => $chapter->title,
            'body' => $chapter->body,
            'prev' => $index > 0 ? $positions[$index - 1] : null,
            'next' => $positions[$index + 1] ?? null,
        ]]);
    }

    public function purchase(Request $request, string $slug, Checkout $checkout): JsonResponse
    {
        $book = Book::published()->where('slug', $slug)->firstOrFail();
        $checkout->buyBook($request->user(), $book);

        return response()->json(['data' => $this->card($book->load('cover'), $request->user())], 201);
    }

    public function pdf(Request $request, string $slug, BookPdf $pdf): Response
    {
        $user = $request->user();
        $book = Book::published()->where('slug', $slug)->with('chapters')->firstOrFail();

        abort_unless($this->access->canDownload($user, $book), 403, 'Скачать можно бесплатную или купленную книгу.');

        $name = (Str::slug($book->title) ?: 'book').'.pdf';

        return response($pdf->render($book, $user), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function card(Book $book, ?User $user): array
    {
        return [
            'id' => $book->id,
            'slug' => $book->slug,
            'title' => $book->title,
            'author_name' => $book->author_name,
            'year' => $book->year,
            'country' => $book->country,
            'lead' => $book->lead,
            'price' => $book->price,
            'is_free' => $book->isFree(),
            'in_subscription' => $book->in_subscription,
            'chapters_count' => $book->chapters_count ?? $book->chapters()->count(),
            'cover' => $book->cover ? ['url' => $book->cover->url(), 'alt' => $book->cover->alt] : null,
            'owned' => $this->access->owns($user, $book),
            'can_read' => $this->access->canRead($user, $book),
            'can_download' => $this->access->canDownload($user, $book),
        ];
    }
}
