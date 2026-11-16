<?php

declare(strict_types=1);

namespace App\Api\Pagination;

use App\Api\Validation\InputValidator;
use Symfony\Component\HttpFoundation\Request;

/**
 * Страница списка: data, ссылки и meta (как ждёт фронтенд):
 * {data, links: {first, last, prev, next}, meta: {current_page, from, last_page,
 * links, path, per_page, to, total}}. Ссылки строятся так же: исходные параметры
 * запроса, затем page, ±3 страницы вокруг текущей и «...» на разрывах.
 */
final class Paginator
{
    private const ON_EACH_SIDE = 3;

    /** Номер страницы из ?page=: мусор и значения меньше 1 дают 1. */
    public static function currentPage(Request $request): int
    {
        $page = filter_var($request->query->get('page'), \FILTER_VALIDATE_INT);

        // Потолок: на гигантском номере OFFSET переполнился бы и запрос упал с 500.
        return false !== $page && $page >= 1 ? min($page, 1_000_000) : 1;
    }

    /**
     * @param list<array<string, mixed>> $items уже готовые элементы этой страницы
     *
     * @return array{data: list<array<string, mixed>>, links: array<string, string|null>, meta: array<string, mixed>}
     */
    public static function page(Request $request, array $items, int $total, int $page, int $perPage): array
    {
        $path = $request->getSchemeAndHttpHost().$request->getPathInfo();
        // Параметры уже очищены (пробелы по краям обрезаны, пустые строки стали null):
        // пустые выпадают из ссылок (http_build_query пропускает null).
        $query = InputValidator::input($request);
        unset($query['page']);

        $lastPage = max((int) ceil($total / $perPage), 1);
        $url = static fn (int $n): string => $path.'?'.http_build_query($query + ['page' => $n], '', '&', \PHP_QUERY_RFC3986);
        $from = [] === $items ? null : ($page - 1) * $perPage + 1;

        return [
            'data' => $items,
            'links' => [
                'first' => $url(1),
                'last' => $url($lastPage),
                'prev' => $page > 1 ? $url($page - 1) : null,
                'next' => $page < $lastPage ? $url($page + 1) : null,
            ],
            'meta' => [
                'current_page' => $page,
                'from' => $from,
                'last_page' => $lastPage,
                'links' => self::links($page, $lastPage, $url),
                'path' => $path,
                'per_page' => $perPage,
                'to' => null === $from ? null : $from + \count($items) - 1,
                'total' => $total,
            ],
        ];
    }

    /**
     * @param \Closure(int): string $url
     *
     * @return list<array<string, mixed>>
     */
    private static function links(int $page, int $lastPage, \Closure $url): array
    {
        $links = [[
            'url' => $page > 1 ? $url($page - 1) : null,
            'label' => '&laquo; Previous',
            'page' => $page > 1 ? $page - 1 : null,
            'active' => false,
        ]];

        foreach (self::window($page, $lastPage) as $part) {
            if (null === $part) {
                $links[] = ['url' => null, 'label' => '...', 'active' => false];
                continue;
            }
            foreach ($part as $n) {
                $links[] = ['url' => $url($n), 'label' => (string) $n, 'page' => $n, 'active' => $n === $page];
            }
        }

        $links[] = [
            'url' => $page < $lastPage ? $url($page + 1) : null,
            'label' => 'Next &raquo;',
            'page' => $page < $lastPage ? $page + 1 : null,
            'active' => false,
        ];

        return $links;
    }

    /**
     * Куски номеров страниц, null — разрыв «...».
     *
     * @return list<list<int>|null>
     */
    private static function window(int $page, int $lastPage): array
    {
        $side = self::ON_EACH_SIDE;
        if ($lastPage < $side * 2 + 8) {
            return [range(1, $lastPage)];
        }

        $window = $side + 4;
        $start = [1, 2];
        $finish = [$lastPage - 1, $lastPage];

        if ($page <= $window) {
            return [range(1, $window + $side), null, $finish];
        }
        if ($page > $lastPage - $window) {
            return [$start, null, range($lastPage - ($window + ($side - 1)), $lastPage)];
        }

        return [$start, null, range($page - $side, $page + $side), null, $finish];
    }
}
