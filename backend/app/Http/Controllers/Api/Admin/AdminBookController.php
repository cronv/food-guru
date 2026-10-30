<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\ActivityLog;
use App\Models\Book;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminBookController
{
    use GuardsCommerceFields;

    public function index(): JsonResponse
    {
        $books = Book::with('cover')->withCount(['chapters', 'purchases'])->orderByDesc('updated_at')->get();

        return response()->json(['data' => $books->map(fn (Book $b) => $this->row($b))]);
    }

    public function show(Book $book): JsonResponse
    {
        $book->load(['cover', 'chapters'])->loadCount(['chapters', 'purchases']);

        return response()->json(['data' => $this->row($book) + [
            'description' => $book->description,
            'chapters' => $book->chapters->map(fn ($c) => [
                'title' => $c->title, 'body' => $c->body, 'is_preview' => $c->is_preview,
            ]),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->guardCommerce($request, null, $data, ['price', 'in_subscription']);

        $book = DB::transaction(function () use ($data) {
            $book = Book::create(collect($data)->except('chapters')->all() + ['slug' => $data['slug'] ?? $this->slug($data['title'])]);
            $this->syncChapters($book, $data['chapters'] ?? []);

            return $book;
        });

        ActivityLog::record($request->user(), 'book.created', $book, ['title' => $book->title]);

        return $this->show($book->fresh());
    }

    public function update(Request $request, Book $book): JsonResponse
    {
        $data = $this->validated($request, $book);
        $this->guardCommerce($request, $book, $data, ['price', 'in_subscription']);

        DB::transaction(function () use ($book, $data) {
            $fields = collect($data)->except('chapters')->all();
            if (($fields['is_published'] ?? false) && ! $book->published_at) {
                $fields['published_at'] = now();
            }
            $book->update($fields);

            if (array_key_exists('chapters', $data)) {
                $this->syncChapters($book, $data['chapters']);
            }
        });

        ActivityLog::record($request->user(), 'book.updated', $book, ['title' => $book->title]);

        return $this->show($book->fresh());
    }

    /** Главы переписываются целиком, в присланном порядке. */
    private function syncChapters(Book $book, array $chapters): void
    {
        $book->chapters()->delete();

        foreach (array_values($chapters) as $i => $c) {
            $book->chapters()->create([
                'position' => $i + 1,
                'title' => $c['title'],
                'body' => $c['body'],
                'is_preview' => (bool) ($c['is_preview'] ?? false),
            ]);
        }
    }

    private function validated(Request $request, ?Book $book = null): array
    {
        $creating = $book === null;

        return $request->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:160', 'regex:/^[a-z0-9-]+$/', Rule::unique('books', 'slug')->ignore($book?->id)],
            'author_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'year' => ['sometimes', 'nullable', 'integer', 'between:1800,2100'],
            'country' => ['sometimes', 'nullable', 'string', 'max:80'],
            'lead' => ['sometimes', 'nullable', 'string', 'max:400'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'price' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'in_subscription' => ['sometimes', 'boolean'],
            'cover_media_id' => ['sometimes', 'nullable', 'integer', 'exists:media,id'],
            'is_published' => ['sometimes', 'boolean'],
            'chapters' => ['sometimes', 'array', 'max:200'],
            'chapters.*.title' => ['required', 'string', 'max:200'],
            'chapters.*.body' => ['required', 'string', 'max:200000'],
            'chapters.*.is_preview' => ['sometimes', 'boolean'],
        ]);
    }

    private function row(Book $b): array
    {
        return [
            'id' => $b->id,
            'slug' => $b->slug,
            'title' => $b->title,
            'author_name' => $b->author_name,
            'year' => $b->year,
            'country' => $b->country,
            'lead' => $b->lead,
            'price' => $b->price,
            'in_subscription' => $b->in_subscription,
            'is_published' => $b->is_published,
            'cover_media_id' => $b->cover_media_id,
            'cover' => $b->cover ? ['url' => $b->cover->url(), 'alt' => $b->cover->alt] : null,
            'chapters_count' => (int) ($b->chapters_count ?? 0),
            'purchases_count' => (int) ($b->purchases_count ?? 0),
        ];
    }

    private function slug(string $title): string
    {
        $base = Str::slug($title) ?: 'kniga';
        $slug = $base;
        for ($i = 2; Book::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
