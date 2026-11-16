<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\Pagination\Paginator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class PaginatorTest extends TestCase
{
    /** @return list<string> */
    private function labels(int $page, int $total): array
    {
        $result = Paginator::page(Request::create('http://api.test/x'), [['id' => 1]], $total, $page, 1);

        /** @var list<array{label: string}> $links */
        $links = $result['meta']['links'];

        return array_column($links, 'label');
    }

    public function testSmallSetShowsAllPages(): void
    {
        self::assertSame(['&laquo; Previous', '1', '2', '3', 'Next &raquo;'], $this->labels(2, 3));
    }

    public function testLongSetUsesStandardWindow(): void
    {
        self::assertSame(['&laquo; Previous', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '...', '29', '30', 'Next &raquo;'], $this->labels(1, 30));
        self::assertSame(['&laquo; Previous', '1', '2', '...', '12', '13', '14', '15', '16', '17', '18', '...', '29', '30', 'Next &raquo;'], $this->labels(15, 30));
        self::assertSame(['&laquo; Previous', '1', '2', '...', '21', '22', '23', '24', '25', '26', '27', '28', '29', '30', 'Next &raquo;'], $this->labels(30, 30));
    }

    public function testUrlsKeepQueryAndPutPageLast(): void
    {
        $request = Request::create('http://api.test/api/v1/recipes', 'GET', ['page' => '2', 'sort' => 'new', 'q' => '  ', 'tags' => ['a']]);
        $result = Paginator::page($request, [], 5, 2, 2);

        self::assertSame('http://api.test/api/v1/recipes?sort=new&tags%5B0%5D=a&page=3', $result['links']['next']);
        self::assertNull($result['meta']['from']);
        self::assertSame(3, $result['meta']['last_page']);
    }

    public function testGarbagePageIsFirst(): void
    {
        self::assertSame(1, Paginator::currentPage(Request::create('/x', 'GET', ['page' => 'abc'])));
        self::assertSame(1, Paginator::currentPage(Request::create('/x', 'GET', ['page' => '-3'])));
        self::assertSame(4, Paginator::currentPage(Request::create('/x', 'GET', ['page' => '4'])));
    }
}
