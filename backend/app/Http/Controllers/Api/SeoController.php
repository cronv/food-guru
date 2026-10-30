<?php

namespace App\Http\Controllers\Api;

use App\Models\AcademySession;
use App\Models\Book;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Contest;
use App\Models\JournalIssue;
use App\Models\Product;
use App\Models\Recipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Всё публичное для карты сайта. Сам sitemap.xml собирает SSR-сервер сайта:
 * адреса должны быть на домене сайта, а не API. Здесь — пути и даты изменения.
 *
 * Только то, что видно гостю: черновики, закрытые материалы академии,
 * кабинет и корзина в карту не попадают.
 */
class SeoController
{
    public function sitemap(): JsonResponse
    {
        // Карту запрашивают роботы, а не люди: час кэша не даст им нагружать базу.
        $urls = Cache::remember('seo:sitemap', now()->addHour(), function () {
            $items = [];
            $add = function (string $path, $lastmod = null, float $priority = 0.5, string $freq = 'weekly') use (&$items) {
                $items[] = [
                    'path' => $path,
                    'lastmod' => $lastmod ? Carbon::parse($lastmod)->toDateString() : null,
                    'priority' => $priority,
                    'changefreq' => $freq,
                ];
            };

            $add('/', null, 1.0, 'daily');
            foreach (['/recipes' => 0.9, '/reels' => 0.6, '/collections' => 0.7, '/books' => 0.7, '/shop' => 0.7,
                '/club' => 0.6, '/academy' => 0.6, '/contests' => 0.5, '/journal' => 0.5, '/rating' => 0.4, '/help' => 0.3] as $path => $p) {
                $add($path, null, $p, 'daily');
            }

            Recipe::published()->select(['slug', 'updated_at'])->orderBy('id')
                ->each(fn ($r) => $add("/recipes/{$r->slug}", $r->updated_at, 0.8));
            Category::query()->select(['slug', 'updated_at'])->orderBy('id')
                ->each(fn ($c) => $add("/categories/{$c->slug}", $c->updated_at, 0.6));
            Collection::where('is_published', true)->select(['slug', 'updated_at'])->orderBy('id')
                ->each(fn ($c) => $add("/collections/{$c->slug}", $c->updated_at, 0.6));
            Book::published()->select(['slug', 'updated_at'])->orderBy('id')
                ->each(fn ($b) => $add("/books/{$b->slug}", $b->updated_at, 0.6));
            Product::published()->select(['slug', 'updated_at'])->orderBy('id')
                ->each(fn ($p) => $add("/shop/{$p->slug}", $p->updated_at, 0.6));
            Contest::published()->select(['slug', 'updated_at'])->orderBy('id')
                ->each(fn ($c) => $add("/contests/{$c->slug}", $c->updated_at, 0.4));
            AcademySession::published()->select(['slug', 'updated_at'])->orderBy('id')
                ->each(fn ($s) => $add("/academy/{$s->slug}", $s->updated_at, 0.4));
            JournalIssue::published()->select(['slug', 'updated_at'])->orderBy('id')
                ->each(fn ($j) => $add("/journal/{$j->slug}", $j->updated_at, 0.4));

            return $items;
        });

        return response()->json(['data' => $urls]);
    }
}
