<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Tests\Functional\ApiTestCase;

/** Каталог и карточка рецепта — поведение RecipeController и RecipeEngagementController. */
final class RecipesTest extends ApiTestCase
{
    private function tag(string $slug, int ...$recipes): void
    {
        $this->db()->insert('tags', ['slug' => $slug, 'title' => $slug]);
        $tagId = (int) $this->db()->lastInsertId();
        foreach ($recipes as $recipe) {
            $this->db()->insert('recipe_tag', ['recipe_id' => $recipe, 'tag_id' => $tagId]);
        }
    }

    public function testCatalogShowsOnlyPublishedAndPaginates(): void
    {
        $this->insertRecipe('vidno', null);
        $this->insertRecipe('chernovik', null, 'draft', null);
        $this->insertRecipe('zavtra', null, 'published', '+1 day');

        $body = $this->json('GET', '/api/v1/recipes?per_page=1');

        self::assertResponseIsSuccessful();
        self::assertSame(['vidno'], array_column($body['data'], 'slug'));
        self::assertSame(['current_page', 'from', 'last_page', 'links', 'path', 'per_page', 'to', 'total'], array_keys($body['meta']));
        self::assertSame(1, $body['meta']['total']);
        self::assertSame(['first', 'last', 'prev', 'next'], array_keys($body['links']));
    }

    public function testTagsFilterRequiresEveryTag(): void
    {
        $both = $this->insertRecipe('oba', null);
        $one = $this->insertRecipe('odin', null);
        $this->tag('syr', $both, $one);
        $this->tag('uzhin', $both);

        $body = $this->json('GET', '/api/v1/recipes?tags[]=syr&tags[]=uzhin');

        self::assertSame(['oba'], array_column($body['data'], 'slug'));
        self::assertSame(['syr', 'uzhin'], array_column($body['data'][0]['tags'], 'slug'));
    }

    public function testInvalidFiltersGive422(): void
    {
        $body = $this->json('GET', '/api/v1/recipes?category=net-takoy&per_page=100');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('The selected category is invalid. (and 1 more error)', $body['message']);
        self::assertSame(['category', 'per_page'], array_keys($body['errors']));
    }

    public function testCardHasNoRatingWithoutReviewsAndRoundsAverage(): void
    {
        $quiet = $this->insertRecipe('tikho', null);
        $rated = $this->insertRecipe('ocenen', null);
        foreach ([5, 4, 4] as $i => $rating) {
            $author = $this->user();
            $this->db()->insert('reviews', ['recipe_id' => $rated, 'user_id' => $author->getId(), 'rating' => $rating, 'body' => 'Отзыв номер '.$i]);
        }

        $cards = array_column($this->json('GET', '/api/v1/recipes')['data'], null, 'slug');

        self::assertArrayNotHasKey('rating', $cards['tikho']);
        self::assertSame(0, $cards['tikho']['reviews_count']);
        self::assertSame(4.3, $cards['ocenen']['rating']);
        self::assertSame(3, $cards['ocenen']['reviews_count']);
        self::assertGreaterThan(0, $quiet);
    }

    public function testGuestCardHasNoPersonalFieldsButUserGetsThem(): void
    {
        $recipe = $this->insertRecipe('lichnoe', null);

        $guest = $this->json('GET', '/api/v1/recipes/lichnoe')['data'];
        self::assertArrayNotHasKey('is_favorite', $guest);
        self::assertArrayNotHasKey('my_reaction', $guest);

        $user = $this->user();
        $this->db()->insert('favorites', ['user_id' => $user->getId(), 'recipe_id' => $recipe]);
        $this->db()->insert('recipe_reactions', ['user_id' => $user->getId(), 'recipe_id' => $recipe, 'reaction' => 'like']);
        $this->login($user);

        $mine = $this->json('GET', '/api/v1/recipes/lichnoe')['data'];
        self::assertTrue($mine['is_favorite']);
        self::assertFalse($mine['in_cookbook']);
        self::assertSame('like', $mine['my_reaction']);
    }

    public function testUnpublishedRecipeIs404(): void
    {
        $this->insertRecipe('skryt', null, 'moderation', null);

        $this->json('GET', '/api/v1/recipes/skryt');
        self::assertResponseStatusCodeSame(404);
    }

    public function testViewCountsOncePerDayPerPerson(): void
    {
        $this->insertRecipe('smotrim', null);
        $this->login($this->user());
        $csrf = $this->xsrf();

        $first = $this->json('POST', '/api/v1/recipes/smotrim/view', $csrf, []);
        $second = $this->json('POST', '/api/v1/recipes/smotrim/view', $csrf, []);

        self::assertSame(1, $first['data']['views_count']);
        self::assertSame(1, $second['data']['views_count']);
    }

    public function testReviewsListShowsOnlyPublished(): void
    {
        $recipe = $this->insertRecipe('otzyvy', null);
        foreach (['published', 'hidden'] as $status) {
            $this->db()->insert('reviews', ['recipe_id' => $recipe, 'user_id' => $this->user()->getId(), 'rating' => 5, 'body' => 'Статус '.$status, 'status' => $status]);
        }

        $body = $this->json('GET', '/api/v1/recipes/otzyvy/reviews');

        self::assertSame(['Статус published'], array_column($body['data'], 'body'));
        self::assertSame(['id', 'rating', 'body', 'status', 'created_at', 'author', 'media'], array_keys($body['data'][0]));
    }
}
