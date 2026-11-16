<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Tests\Functional\ApiTestCase;

/** Повторяет поведение CatalogController::categories/tags. */
final class CatalogTest extends ApiTestCase
{
    private function category(string $slug, int $position, ?int $parentId = null): int
    {
        $this->db()->insert('categories', ['slug' => $slug, 'title' => ucfirst($slug), 'position' => $position, 'parent_id' => $parentId]);

        return (int) $this->db()->lastInsertId();
    }

    public function testCategoriesCountOnlyPublishedRecipesAndNestChildren(): void
    {
        $soups = $this->category('supy', 2);
        $breakfast = $this->category('zavtraki', 1);
        $this->category('kholodnye', 2, $soups);
        $this->category('goryachie', 1, $soups);

        $this->insertRecipe('borsch', $soups);
        $this->insertRecipe('shchi', $soups);
        $this->insertRecipe('draft', $soups, 'draft', null);
        // Дата публикации ещё не наступила — гость рецепт не видит, счётчик тоже.
        $this->insertRecipe('future', $soups, 'published', '+1 day');

        $data = $this->json('GET', '/api/v1/categories')['data'];

        self::assertResponseIsSuccessful();
        self::assertSame(['zavtraki', 'supy'], array_column($data, 'slug'));
        self::assertSame(0, $data[0]['recipes_count']);
        self::assertSame($breakfast, $data[0]['id']);
        self::assertSame(2, $data[1]['recipes_count']);
        self::assertSame(['goryachie', 'kholodnye'], array_column($data[1]['children'], 'slug'));
        self::assertSame(['id', 'slug', 'title'], array_keys($data[1]['children'][0]));
        self::assertSame(['id', 'slug', 'title', 'description', 'recipes_count', 'children'], array_keys($data[0]));
    }

    public function testTagsWithoutPublishedRecipesAreHiddenAndPopularGoFirst(): void
    {
        $recipes = [$this->insertRecipe('a', null), $this->insertRecipe('b', null), $this->insertRecipe('hidden', null, 'draft', null)];
        $tags = [];
        foreach (['syr' => 'Сыр', 'uzhin' => 'На ужин', 'pusto' => 'Пусто'] as $slug => $title) {
            $this->db()->insert('tags', ['slug' => $slug, 'title' => $title]);
            $tags[$slug] = (int) $this->db()->lastInsertId();
        }
        $attach = fn (int $recipe, string $tag) => $this->db()->insert('recipe_tag', ['recipe_id' => $recipe, 'tag_id' => $tags[$tag]]);
        $attach($recipes[0], 'syr');
        $attach($recipes[0], 'uzhin');
        $attach($recipes[1], 'uzhin');
        $attach($recipes[2], 'pusto');

        $data = $this->json('GET', '/api/v1/tags')['data'];

        self::assertSame([
            ['slug' => 'uzhin', 'title' => 'На ужин', 'recipes_count' => 2],
            ['slug' => 'syr', 'title' => 'Сыр', 'recipes_count' => 1],
        ], $data);
    }

    public function testCorsAllowsOnlyOwnFrontendsWithCookies(): void
    {
        $this->json('GET', '/api/v1/tags');
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', self::SITE);
        self::assertResponseHeaderSame('Access-Control-Allow-Credentials', 'true');

        $this->json('GET', '/api/v1/tags', ['HTTP_ORIGIN' => 'http://localhost:4300']);
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', 'http://localhost:4300');

        $this->json('GET', '/api/v1/tags', ['HTTP_ORIGIN' => 'https://evil.example']);
        self::assertResponseNotHasHeader('Access-Control-Allow-Origin');
    }

    public function testUnknownApiRouteAnswersNotFound(): void
    {
        $body = $this->json('GET', '/api/v1/net-takogo');

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['message' => 'Not Found.'], $body);
    }
}
