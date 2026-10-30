<?php

namespace Tests\Feature;

use App\Enums\RecipeStatus;
use App\Enums\UserRole;
use App\Models\Book;
use App\Models\Product;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_в_карте_сайта_только_публичное(): void
    {
        $author = $this->user(UserRole::ContentManager)->id;
        Recipe::create(['author_id' => $author, 'slug' => 'open', 'title' => 'Открытый', 'status' => RecipeStatus::Published, 'published_at' => now()->subDay()]);
        Recipe::create(['author_id' => $author, 'slug' => 'draft', 'title' => 'Черновик', 'status' => RecipeStatus::Draft]);
        // Запланирован на завтра — пока не публичный.
        Recipe::create(['author_id' => $author, 'slug' => 'future', 'title' => 'Завтра', 'status' => RecipeStatus::Published, 'published_at' => now()->addDay()]);
        Book::create(['slug' => 'kniga', 'title' => 'Книга', 'is_published' => true]);
        Book::create(['slug' => 'skrytaya', 'title' => 'Скрытая', 'is_published' => false]);
        Product::create(['slug' => 'nozh', 'title' => 'Нож', 'price' => 100000, 'is_published' => true]);

        $paths = array_column($this->getJson('/api/v1/seo/sitemap')->assertOk()->json('data'), 'path');

        $this->assertContains('/', $paths);
        $this->assertContains('/recipes/open', $paths);
        $this->assertContains('/books/kniga', $paths);
        $this->assertContains('/shop/nozh', $paths);
        $this->assertNotContains('/recipes/draft', $paths);
        $this->assertNotContains('/recipes/future', $paths);
        $this->assertNotContains('/books/skrytaya', $paths);
        // Личное и платное — никогда.
        foreach ($paths as $p) {
            $this->assertDoesNotMatchRegularExpression('#^/(account|cart|wallet|orders|cookbook|login)#', $p);
        }
    }
}
