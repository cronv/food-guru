<?php

namespace Tests\Feature;

use App\Enums\RecipeStatus;
use App\Enums\UserRole;
use App\Models\Recipe;
use App\Models\RecipeReaction;
use App\Models\RecipeReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Оценки, просмотры, жалобы и вкладки шагов. */
class RecipeEngagementTest extends TestCase
{
    use RefreshDatabase;

    private Recipe $recipe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', 'http://localhost:4200');

        $this->recipe = Recipe::create([
            'author_id' => $this->user(UserRole::ContentManager)->id,
            'slug' => 'fondan',
            'title' => 'Фондан',
            'status' => RecipeStatus::Published,
            'published_at' => now()->subDay(),
        ]);
    }

    private function react($user, ?string $reaction)
    {
        $url = "/api/v1/recipes/{$this->recipe->id}/reaction";

        return $reaction
            ? $this->actingAs($user)->putJson($url, ['reaction' => $reaction])
            : $this->actingAs($user)->deleteJson($url);
    }

    /* ---------- оценки ---------- */

    public function test_лайк_засчитывается_и_виден_в_карточке(): void
    {
        $user = $this->user();

        $this->react($user, 'like')
            ->assertOk()
            ->assertJsonPath('data.likes_count', 1)
            ->assertJsonPath('data.dislikes_count', 0)
            ->assertJsonPath('data.my_reaction', 'like');

        $this->actingAs($user)->getJson('/api/v1/recipes/fondan')
            ->assertJsonPath('data.likes_count', 1)
            ->assertJsonPath('data.my_reaction', 'like');
    }

    public function test_повторный_лайк_не_удваивает_счётчик(): void
    {
        $user = $this->user();

        $this->react($user, 'like');
        $this->react($user, 'like')->assertJsonPath('data.likes_count', 1);

        $this->assertSame(1, RecipeReaction::count());
    }

    public function test_смена_оценки_переносит_голос(): void
    {
        $user = $this->user();

        $this->react($user, 'like');
        $this->react($user, 'dislike')
            ->assertJsonPath('data.likes_count', 0)
            ->assertJsonPath('data.dislikes_count', 1)
            ->assertJsonPath('data.my_reaction', 'dislike');
    }

    public function test_снятие_оценки_уменьшает_счётчик(): void
    {
        $user = $this->user();

        $this->react($user, 'like');
        $this->react($user, null)
            ->assertOk()
            ->assertJsonPath('data.likes_count', 0)
            ->assertJsonPath('data.my_reaction', null);
    }

    public function test_оценки_разных_людей_складываются(): void
    {
        $this->react($this->user(), 'like');
        $this->react($this->user(), 'like');
        $this->react($this->user(), 'dislike')
            ->assertJsonPath('data.likes_count', 2)
            ->assertJsonPath('data.dislikes_count', 1);
    }

    public function test_гость_и_неподтверждённый_не_оценивают(): void
    {
        $this->putJson("/api/v1/recipes/{$this->recipe->id}/reaction", ['reaction' => 'like'])->assertUnauthorized();
        $this->react($this->unverified(), 'like')->assertForbidden();

        $this->assertSame(0, (int) $this->recipe->fresh()->likes_count);
    }

    public function test_неизвестная_оценка_отклоняется(): void
    {
        $this->react($this->user(), 'love')->assertStatus(422);
    }

    public function test_черновик_оценить_нельзя(): void
    {
        $this->recipe->forceFill(['status' => RecipeStatus::Draft, 'published_at' => null])->save();

        $this->react($this->user(), 'like')->assertNotFound();
    }

    public function test_гостю_поле_моей_оценки_не_отдаётся(): void
    {
        $this->getJson('/api/v1/recipes/fondan')
            ->assertOk()
            ->assertJsonPath('data.likes_count', 0)
            ->assertJsonMissingPath('data.my_reaction');
    }

    /* ---------- просмотры ---------- */

    public function test_просмотр_считается_раз_в_сутки_на_человека(): void
    {
        $this->postJson('/api/v1/recipes/fondan/view')->assertOk()->assertJsonPath('data.views_count', 1);
        $this->postJson('/api/v1/recipes/fondan/view')->assertJsonPath('data.views_count', 1);

        $this->actingAs($this->user())->postJson('/api/v1/recipes/fondan/view')
            ->assertJsonPath('data.views_count', 2);

        $this->travel(1)->days();
        $this->postJson('/api/v1/recipes/fondan/view')->assertJsonPath('data.views_count', 3);
    }

    public function test_открытие_карточки_просмотр_не_считает(): void
    {
        // Карточку запрашивает и сервер рендера — иначе каждый заход шёл бы дважды.
        $this->getJson('/api/v1/recipes/fondan')->assertJsonPath('data.views_count', 0);
    }

    public function test_просмотр_неопубликованного_не_считается(): void
    {
        $this->recipe->forceFill(['status' => RecipeStatus::Draft, 'published_at' => null])->save();

        $this->postJson('/api/v1/recipes/fondan/view')->assertNotFound();
    }

    /* ---------- жалобы ---------- */

    public function test_жалоба_сохраняется(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson('/api/v1/recipes/fondan/reports', ['reason' => 'amounts', 'message' => 'Мало муки'])
            ->assertCreated();

        $this->assertDatabaseHas('recipe_reports', [
            'recipe_id' => $this->recipe->id, 'user_id' => $user->id, 'reason' => 'amounts', 'status' => 'open',
        ]);
    }

    public function test_повторная_жалоба_обновляет_открытую(): void
    {
        $user = $this->user();

        $this->actingAs($user)->postJson('/api/v1/recipes/fondan/reports', ['reason' => 'spam'])->assertCreated();
        $this->actingAs($user)->postJson('/api/v1/recipes/fondan/reports', ['reason' => 'error'])->assertSuccessful();

        $this->assertSame(1, RecipeReport::count());
        $this->assertSame('error', RecipeReport::first()->reason);
    }

    public function test_причина_другое_требует_описания(): void
    {
        $this->actingAs($this->user())
            ->postJson('/api/v1/recipes/fondan/reports', ['reason' => 'other', 'message' => 'плохо'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');
    }

    public function test_гость_пожаловаться_не_может(): void
    {
        $this->postJson('/api/v1/recipes/fondan/reports', ['reason' => 'spam'])->assertUnauthorized();
    }

    public function test_жалобы_разбирает_руководитель_а_не_менеджер(): void
    {
        $this->actingAs($this->user())->postJson('/api/v1/recipes/fondan/reports', ['reason' => 'spam']);
        $report = RecipeReport::first();

        $this->actingAs($this->user(UserRole::ContentManager))->getJson('/api/v1/admin/reports')->assertForbidden();

        $lead = $this->user(UserRole::EditorLead);
        $this->actingAs($lead)->getJson('/api/v1/admin/reports')
            ->assertOk()
            ->assertJsonPath('meta.open_total', 1)
            ->assertJsonPath('data.0.reason_label', 'Спам или реклама')
            ->assertJsonPath('data.0.recipe.slug', 'fondan');

        $this->actingAs($lead)->postJson("/api/v1/admin/reports/{$report->id}/resolve", ['status' => 'resolved'])
            ->assertOk();

        $this->assertSame('resolved', $report->fresh()->status);
        $this->assertSame($lead->id, $report->fresh()->resolved_by);
        $this->actingAs($lead)->getJson('/api/v1/admin/reports')->assertJsonCount(0, 'data');
    }

    /* ---------- вкладки шагов ---------- */

    public function test_вкладки_шага_сохраняются_и_отдаются(): void
    {
        $author = $this->recipe->author;

        $this->actingAs($author)
            ->patchJson("/api/v1/admin/recipes/{$this->recipe->id}", [
                'steps' => [[
                    'body' => 'Растопите шоколад.',
                    'tip' => 'Не выше 50 °C.',
                    'substitution' => 'Масло — кокосовым.',
                    'chef_secret' => 'Щепотка соли усиливает вкус шоколада.',
                ]],
            ])
            ->assertOk();

        $this->getJson('/api/v1/recipes/fondan')
            ->assertJsonPath('data.steps.0.tip', 'Не выше 50 °C.')
            ->assertJsonPath('data.steps.0.substitution', 'Масло — кокосовым.')
            ->assertJsonPath('data.steps.0.chef_secret', 'Щепотка соли усиливает вкус шоколада.');
    }
}
