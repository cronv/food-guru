<?php

namespace Tests\Feature;

use App\Enums\RecipeStatus;
use App\Enums\UserRole;
use App\Mail\RecipeModeratedMail;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Рецепты читателей: черновик → модерация → публикация или возврат. */
class ReaderRecipeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Mail::fake();
        $this->withHeader('Origin', 'http://localhost:4200');
    }

    /** Настоящий PNG 1×1: в контейнере нет GD, а тип проверяется по содержимому. */
    private function photo(User $user): int
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        return $this->actingAs($user)
            ->post('/api/v1/me/recipes/media', ['file' => new UploadedFile($path, 'dish.png', 'image/png', null, true)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.id');
    }

    private function full(User $user): array
    {
        return [
            'title' => 'Бабушкины блины',
            'lead' => 'Тонкие блины на молоке, как в детстве у бабушки на даче.',
            'time_minutes' => 40,
            'servings' => 4,
            'cover_media_id' => $this->photo($user),
            'ingredients' => [['name' => 'Мука', 'amount' => 200, 'unit' => 'г'], ['name' => 'Молоко', 'amount' => 500, 'unit' => 'мл']],
            'steps' => [['body' => 'Смешайте тесто.'], ['body' => 'Жарьте на горячей сковороде.']],
        ];
    }

    public function test_полный_путь_от_черновика_до_публикации_с_баллами_и_письмом(): void
    {
        $reader = $this->user();
        $id = $this->actingAs($reader)->postJson('/api/v1/me/recipes', $this->full($reader))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');

        // Черновик не виден на сайте.
        $slug = Recipe::find($id)->slug;
        $this->getJson("/api/v1/recipes/{$slug}")->assertNotFound();

        $this->actingAs($reader)->postJson("/api/v1/me/recipes/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'moderation');

        $lead = $this->user(UserRole::EditorLead);
        $this->actingAs($lead)->postJson("/api/v1/admin/recipes/{$id}/publish")->assertOk();

        $this->getJson("/api/v1/recipes/{$slug}")->assertOk()->assertJsonPath('data.title', 'Бабушкины блины');
        // +25 за рецепт; ещё +1 читатель получил за заход на сайт — его не считаем.
        $this->assertSame(25, (int) \DB::table('rating_events')->where('user_id', $reader->id)->where('source', 'recipe')->sum('points'));
        Mail::assertQueued(RecipeModeratedMail::class, fn ($m) => $m->published && $m->hasTo($reader->email));
    }

    public function test_возврат_с_комментарием_правка_и_повторная_отправка(): void
    {
        $reader = $this->user();
        $id = $this->actingAs($reader)->postJson('/api/v1/me/recipes', $this->full($reader))->json('data.id');
        $this->actingAs($reader)->postJson("/api/v1/me/recipes/{$id}/submit");

        $this->actingAs($this->user(UserRole::EditorLead))
            ->postJson("/api/v1/admin/recipes/{$id}/reject", ['note' => 'Укажите жирность молока.'])->assertOk();
        Mail::assertQueued(RecipeModeratedMail::class, fn ($m) => ! $m->published);

        $this->actingAs($reader)->getJson('/api/v1/me/recipes')
            ->assertJsonPath('data.0.status', 'rejected')
            ->assertJsonPath('data.0.moderation_note', 'Укажите жирность молока.')
            ->assertJsonPath('data.0.can_edit', true);

        $this->actingAs($reader)->patchJson("/api/v1/me/recipes/{$id}", ['lead' => 'Тонкие блины на молоке 3,2%, как в детстве у бабушки.'])->assertOk();
        $this->actingAs($reader)->postJson("/api/v1/me/recipes/{$id}/submit")->assertOk();
    }

    public function test_неполный_рецепт_на_модерацию_не_уходит(): void
    {
        $reader = $this->user();
        $id = $this->actingAs($reader)->postJson('/api/v1/me/recipes', ['title' => 'Суп'])->json('data.id');

        $this->actingAs($reader)->postJson("/api/v1/me/recipes/{$id}/submit")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cover_media_id', 'lead', 'ingredients', 'steps', 'time_minutes']);
    }

    public function test_на_модерации_и_после_публикации_читатель_не_правит(): void
    {
        $reader = $this->user();
        $id = $this->actingAs($reader)->postJson('/api/v1/me/recipes', $this->full($reader))->json('data.id');
        $this->actingAs($reader)->postJson("/api/v1/me/recipes/{$id}/submit");

        $this->actingAs($reader)->patchJson("/api/v1/me/recipes/{$id}", ['title' => 'Новое'])->assertStatus(422)->assertJsonPath('code', 'not_editable');

        // Забрал с проверки — снова можно править.
        $this->actingAs($reader)->postJson("/api/v1/me/recipes/{$id}/withdraw")->assertOk()->assertJsonPath('data.status', 'draft');
        $this->actingAs($reader)->patchJson("/api/v1/me/recipes/{$id}", ['title' => 'Новое название'])->assertOk();

        $this->actingAs($reader)->postJson("/api/v1/me/recipes/{$id}/submit");
        $this->actingAs($this->user(UserRole::EditorLead))->postJson("/api/v1/admin/recipes/{$id}/publish");

        $this->actingAs($reader)->patchJson("/api/v1/me/recipes/{$id}", ['title' => 'Подмена после публикации'])->assertStatus(422);
        $this->actingAs($reader)->deleteJson("/api/v1/me/recipes/{$id}")->assertStatus(422);
        $this->assertSame(RecipeStatus::Published, Recipe::find($id)->status);
    }

    public function test_чужие_фото_и_чужие_рецепты_недоступны(): void
    {
        $owner = $this->user();
        $stranger = $this->user();
        $strangerPhoto = $this->photo($stranger);

        $this->actingAs($owner)->postJson('/api/v1/me/recipes', ['title' => 'Пирог', 'cover_media_id' => $strangerPhoto])
            ->assertStatus(422)->assertJsonValidationErrors('cover_media_id');

        $id = $this->actingAs($owner)->postJson('/api/v1/me/recipes', ['title' => 'Пирог'])->json('data.id');

        $this->actingAs($stranger)->getJson("/api/v1/me/recipes/{$id}")->assertNotFound();
        $this->actingAs($stranger)->patchJson("/api/v1/me/recipes/{$id}", ['title' => 'Взлом'])->assertNotFound();
        $this->actingAs($stranger)->deleteJson("/api/v1/me/recipes/{$id}")->assertNotFound();
    }

    public function test_только_картинки_и_только_с_подтверждённой_почтой(): void
    {
        $this->actingAs($this->user())
            ->post('/api/v1/me/recipes/media', ['file' => UploadedFile::fake()->create('virus.php', 10, 'text/x-php')], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->actingAs($this->unverified())->postJson('/api/v1/me/recipes', ['title' => 'Суп'])->assertForbidden();
    }

    public function test_редакция_не_видит_неотправленные_черновики_читателей(): void
    {
        $reader = $this->user();
        $draftId = $this->actingAs($reader)->postJson('/api/v1/me/recipes', ['title' => 'Личный черновик'])->json('data.id');
        $sentId = $this->actingAs($reader)->postJson('/api/v1/me/recipes', $this->full($reader))->json('data.id');
        $this->actingAs($reader)->postJson("/api/v1/me/recipes/{$sentId}/submit");

        $ids = array_column($this->actingAs($this->user(UserRole::EditorLead))->getJson('/api/v1/admin/recipes')->json('data'), 'id');

        $this->assertContains($sentId, $ids);
        $this->assertNotContains($draftId, $ids);
    }

    public function test_читатель_не_может_задать_адрес_и_seo_поля(): void
    {
        $reader = $this->user();
        $id = $this->actingAs($reader)->postJson('/api/v1/me/recipes', [
            'title' => 'Плов', 'slug' => 'kazino-bonus', 'og_description' => 'Реклама',
        ])->json('data.id');

        $recipe = Recipe::find($id);
        $this->assertSame('plov', $recipe->slug);
        $this->assertNull($recipe->og_description);
    }
}
