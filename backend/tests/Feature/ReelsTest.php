<?php

namespace Tests\Feature;

use App\Enums\RecipeStatus;
use App\Enums\UserRole;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Видеорецепты: публичная лента и загрузка ролика из админки. */
class ReelsTest extends TestCase
{
    use RefreshDatabase;

    private function recipe(int $authorId, array $attributes = []): Recipe
    {
        static $n = 0;
        $n++;

        return Recipe::create(array_merge([
            'author_id' => $authorId,
            'slug' => "recept-{$n}",
            'title' => "Рецепт {$n}",
            'status' => RecipeStatus::Published,
            'published_at' => now()->subDay(),
        ], $attributes));
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->withHeader('Origin', 'http://localhost:4200');
    }

    public function test_лента_содержит_только_опубликованные_рецепты_с_роликом(): void
    {
        $author = $this->user(UserRole::ContentManager);

        $with = $this->recipe($author->id, ['reel_path' => 'reels/demo.mp4']);
        $this->recipe($author->id);
        $this->recipe($author->id, ['reel_path' => 'reels/draft.mp4', 'status' => RecipeStatus::Draft, 'published_at' => null]);

        $response = $this->getJson('/api/v1/reels')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.slug', $with->slug);
        $this->assertStringEndsWith('/storage/reels/demo.mp4', $response->json('data.0.reel_url'));
    }

    public function test_у_рецепта_без_ролика_ссылки_нет(): void
    {
        $recipe = $this->recipe($this->user(UserRole::ContentManager)->id);

        $this->getJson("/api/v1/recipes/{$recipe->slug}")
            ->assertOk()
            ->assertJsonPath('data.reel_url', null);
    }

    public function test_главная_отдаёт_блок_рилсов(): void
    {
        $author = $this->user(UserRole::ContentManager);
        $this->recipe($author->id, ['reel_path' => 'reels/demo.mp4']);

        $this->getJson('/api/v1/home')->assertOk()->assertJsonCount(1, 'data.reels');
    }

    public function test_автор_загружает_ролик_к_своему_рецепту(): void
    {
        $author = $this->user(UserRole::ContentManager);
        $recipe = $this->recipe($author->id);

        $response = $this->actingAs($author)
            ->postJson("/api/v1/admin/recipes/{$recipe->id}/reel", [
                'file' => UploadedFile::fake()->create('reel.mp4', 500, 'video/mp4'),
            ])
            ->assertOk();

        $path = $recipe->fresh()->reel_path;
        $this->assertMatchesRegularExpression('#^reels/[0-9a-f-]{36}\.mp4$#', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertNotNull($response->json('data.reel_url'));
    }

    public function test_имя_файла_на_путь_не_влияет(): void
    {
        $author = $this->user(UserRole::ContentManager);
        $recipe = $this->recipe($author->id);

        $this->actingAs($author)
            ->postJson("/api/v1/admin/recipes/{$recipe->id}/reel", [
                'file' => UploadedFile::fake()->create('../../shell.mp4', 500, 'video/mp4'),
            ])
            ->assertOk();

        $path = $recipe->fresh()->reel_path;
        $this->assertStringNotContainsString('shell', $path);
        $this->assertStringNotContainsString('..', $path);
    }

    public function test_файл_с_расширением_php_не_принимается_как_видео(): void
    {
        $author = $this->user(UserRole::ContentManager);
        $recipe = $this->recipe($author->id);

        $this->actingAs($author)
            ->postJson("/api/v1/admin/recipes/{$recipe->id}/reel", [
                'file' => UploadedFile::fake()->create('shell.php', 500, 'video/mp4'),
            ])
            ->assertStatus(422);

        $this->assertNull($recipe->fresh()->reel_path);
    }

    public function test_не_видео_отклоняется(): void
    {
        $author = $this->user(UserRole::ContentManager);
        $recipe = $this->recipe($author->id);

        $this->actingAs($author)
            ->postJson("/api/v1/admin/recipes/{$recipe->id}/reel", [
                'file' => UploadedFile::fake()->create('reel.mp4', 10, 'text/plain'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertNull($recipe->fresh()->reel_path);
    }

    public function test_чужой_рецепт_менеджер_править_не_может(): void
    {
        $owner = $this->user(UserRole::ContentManager);
        $other = $this->user(UserRole::ContentManager);
        $recipe = $this->recipe($owner->id);

        $this->actingAs($other)
            ->postJson("/api/v1/admin/recipes/{$recipe->id}/reel", [
                'file' => UploadedFile::fake()->create('reel.mp4', 500, 'video/mp4'),
            ])
            ->assertForbidden();
    }

    public function test_читатель_ролик_загрузить_не_может(): void
    {
        $recipe = $this->recipe($this->user(UserRole::ContentManager)->id);

        $this->actingAs($this->user())
            ->postJson("/api/v1/admin/recipes/{$recipe->id}/reel", [
                'file' => UploadedFile::fake()->create('reel.mp4', 500, 'video/mp4'),
            ])
            ->assertForbidden();
    }

    public function test_замена_удаляет_прежний_загруженный_файл(): void
    {
        $author = $this->user(UserRole::ContentManager);
        $recipe = $this->recipe($author->id);

        $upload = fn () => $this->actingAs($author)->postJson("/api/v1/admin/recipes/{$recipe->id}/reel", [
            'file' => UploadedFile::fake()->create('reel.mp4', 500, 'video/mp4'),
        ])->assertOk();

        $upload();
        $first = $recipe->fresh()->reel_path;
        $upload();

        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($recipe->fresh()->reel_path);
    }

    public function test_демо_ролик_из_сидов_при_замене_не_удаляется(): void
    {
        $author = $this->user(UserRole::ContentManager);
        $recipe = $this->recipe($author->id, ['reel_path' => 'reels/pasta-reels.mp4']);
        Storage::disk('public')->put('reels/pasta-reels.mp4', 'demo');

        $this->actingAs($author)->deleteJson("/api/v1/admin/recipes/{$recipe->id}/reel")->assertOk();

        $this->assertNull($recipe->fresh()->reel_path);
        Storage::disk('public')->assertExists('reels/pasta-reels.mp4');
    }

    public function test_подсказка_шага_сохраняется_и_отдаётся(): void
    {
        $author = $this->user(UserRole::ContentManager);
        $recipe = $this->recipe($author->id);

        $this->actingAs($author)
            ->patchJson("/api/v1/admin/recipes/{$recipe->id}", [
                'steps' => [['body' => 'Выпекайте.', 'hint' => '200 °C · 9 минут']],
            ])
            ->assertOk()
            ->assertJsonPath('data.steps.0.hint', '200 °C · 9 минут');
    }
}
