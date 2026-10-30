<?php

namespace Tests\Feature;

use App\Enums\RecipeStatus;
use App\Enums\UserRole;
use App\Models\Collection;
use App\Models\CookbookNote;
use App\Models\Recipe;
use App\Models\RecipeReaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Блоки главной и рецептосохранялка. */
class HomeFeedTest extends TestCase
{
    use RefreshDatabase;

    private int $author;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', 'http://localhost:4200');
        $this->author = $this->user(UserRole::ContentManager)->id;
    }

    /** Счётчики не в $fillable — поэтому forceFill, иначе create() молча их отбросит. */
    private function recipe(string $slug, array $extra = []): Recipe
    {
        $recipe = new Recipe;
        $recipe->forceFill(array_merge([
            'author_id' => $this->author,
            'slug' => $slug,
            'title' => ucfirst($slug),
            'status' => RecipeStatus::Published,
            'published_at' => now()->subDays(3),
        ], $extra))->save();

        return $recipe;
    }

    private function save(Recipe $recipe, int $people, $at = null): void
    {
        foreach (range(1, $people) as $i) {
            $recipe->savedBy()->attach($this->user()->id, ['created_at' => $at ?? now()->subDay()]);
        }
    }

    private function home(): array
    {
        return $this->getJson('/api/v1/home')->assertOk()->json('data');
    }

    public function test_популярное_до_восьми_и_по_активности_за_неделю(): void
    {
        foreach (range(1, 10) as $i) {
            $this->recipe("r{$i}");
        }
        $fresh = $this->recipe('svezhiy-hit');
        $old = $this->recipe('staryy-hit');

        $this->save($fresh, 3);
        // Сохранения месячной давности на «популярное за неделю» не влияют.
        $this->save($old, 5, now()->subMonth());

        $popular = $this->home()['popular'];

        $this->assertCount(8, $popular);
        $this->assertSame('svezhiy-hit', $popular[0]['slug']);
    }

    public function test_рейтинг_рецептосохранялки_по_числу_сохранений(): void
    {
        $a = $this->recipe('a');
        $b = $this->recipe('b');
        $this->recipe('nikto-ne-sohranil');

        $this->save($a, 1);
        $this->save($b, 3);

        $saved = $this->home()['most_saved'];

        $this->assertSame(['b', 'a'], array_column($saved, 'slug'));
        $this->assertSame(3, $saved[0]['saves_count']);
    }

    public function test_лучшие_по_доле_лайков_со_сглаживанием(): void
    {
        // Один лайк из одного не должен обойти 19 из 20.
        $this->recipe('odin-layk', ['likes_count' => 1, 'dislikes_count' => 0]);
        $this->recipe('nadezhnyy', ['likes_count' => 19, 'dislikes_count' => 1]);
        $this->recipe('plokhoy', ['likes_count' => 1, 'dislikes_count' => 9]);

        $best = array_column($this->home()['best'], 'slug');

        $this->assertSame('nadezhnyy', $best[0]);
        $this->assertSame('plokhoy', end($best));
    }

    public function test_популярные_подборки_по_сохранениям_и_с_обложкой(): void
    {
        $hit = $this->recipe('hit');
        $quiet = $this->recipe('quiet');
        $this->save($hit, 4);

        $first = Collection::create(['slug' => 'tikhaya', 'title' => 'Тихая', 'is_published' => true]);
        $second = Collection::create(['slug' => 'populyarnaya', 'title' => 'Популярная', 'is_published' => true]);
        $first->recipes()->attach($quiet->id, ['position' => 0]);
        $second->recipes()->attach($hit->id, ['position' => 0]);
        Collection::create(['slug' => 'skrytaya', 'title' => 'Скрытая', 'is_published' => false]);

        $collections = $this->home()['collections'];

        $this->assertSame(['populyarnaya', 'tikhaya'], array_column($collections, 'slug'));
        $this->assertSame(4, $collections[0]['saves_total']);
    }

    public function test_топ_кулинаров_по_баллам_без_неподтверждённых(): void
    {
        $this->user(attributes: ['name' => 'Второй'])->forceFill(['rating_points' => 50])->save();
        $this->user(attributes: ['name' => 'Первый'])->forceFill(['rating_points' => 300])->save();
        $this->unverified(['name' => 'Невидимка'])->forceFill(['rating_points' => 999])->save();

        $cooks = array_column($this->home()['top_cooks'], 'name');

        $this->assertSame(['Первый', 'Второй'], $cooks);
    }

    public function test_редакция_в_топ_кулинаров_и_таблицу_лидеров_не_попадает(): void
    {
        $this->user(attributes: ['name' => 'Читатель'])->forceFill(['rating_points' => 10])->save();
        foreach ([UserRole::ContentManager, UserRole::EditorLead, UserRole::Admin] as $role) {
            $this->user($role, ['name' => 'Сотрудник '.$role->value])->forceFill(['rating_points' => 500])->save();
        }

        $this->assertSame(['Читатель'], array_column($this->home()['top_cooks'], 'name'));
        $this->assertSame(['Читатель'], array_column($this->getJson('/api/v1/rating')->json('data'), 'name'));
    }

    public function test_счётчик_сохранений_есть_в_карточке_рецепта(): void
    {
        $r = $this->recipe('fondan');
        $this->save($r, 2);

        $this->getJson('/api/v1/recipes/fondan')->assertJsonPath('data.saves_count', 2);
    }

    /* ---------- рецептосохранялка ---------- */

    public function test_рецепт_сохраняется_в_рецептосохранялку_отдельно_от_избранного(): void
    {
        $r = $this->recipe('fondan');
        $user = $this->user();

        $this->actingAs($user)->putJson("/api/v1/recipes/{$r->id}/cookbook")
            ->assertOk()
            ->assertJsonPath('data.in_cookbook', true)
            ->assertJsonPath('data.saves_count', 1);
        // Повторное нажатие не дублирует.
        $this->actingAs($user)->putJson("/api/v1/recipes/{$r->id}/cookbook")->assertJsonPath('data.saves_count', 1);

        $this->actingAs($user)->getJson('/api/v1/recipes/fondan')
            ->assertJsonPath('data.in_cookbook', true)
            ->assertJsonPath('data.is_favorite', false);
        $this->actingAs($user)->getJson('/api/v1/me/cookbook/recipes')->assertJsonPath('data.0.slug', 'fondan');
        $this->actingAs($user)->getJson('/api/v1/me/favorites')->assertJsonCount(0, 'data');

        // Избранное на рейтинг рецептосохранялки не влияет.
        $r->favoredBy()->attach($this->user()->id, ['created_at' => now()]);
        $this->assertSame(1, $this->home()['most_saved'][0]['saves_count']);

        $this->actingAs($user)->deleteJson("/api/v1/recipes/{$r->id}/cookbook")
            ->assertJsonPath('data.in_cookbook', false)
            ->assertJsonPath('data.saves_count', 0);
    }

    public function test_черновик_в_рецептосохранялку_не_сохранить(): void
    {
        $r = $this->recipe('draft', ['status' => RecipeStatus::Draft, 'published_at' => null]);

        $this->actingAs($this->user())->putJson("/api/v1/recipes/{$r->id}/cookbook")->assertNotFound();
        $this->assertSame(0, $r->savedBy()->count());
    }

    public function test_гость_в_рецептосохранялку_не_сохраняет(): void
    {
        $r = $this->recipe('open');

        $this->putJson("/api/v1/recipes/{$r->id}/cookbook")->assertUnauthorized();
    }

    public function test_заметка_с_ссылкой_сохраняется_и_видна_только_владельцу(): void
    {
        $owner = $this->user();

        $id = $this->actingAs($owner)
            ->postJson('/api/v1/me/cookbook/notes', ['title' => 'Бабушкин борщ', 'url' => 'https://example.com/borsch'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($owner)->getJson('/api/v1/me/cookbook/notes')->assertJsonCount(1, 'data');
        $this->actingAs($this->user())->getJson('/api/v1/me/cookbook/notes')->assertJsonCount(0, 'data');

        // Чужую заметку нельзя ни поправить, ни удалить — и даже узнать, что она есть.
        $this->actingAs($this->user())->patchJson("/api/v1/me/cookbook/notes/{$id}", ['title' => 'x', 'body' => 'x'])->assertNotFound();
        $this->actingAs($this->user())->deleteJson("/api/v1/me/cookbook/notes/{$id}")->assertNotFound();
        $this->assertSame(1, CookbookNote::count());
    }

    public function test_заметка_без_ссылки_и_текста_не_сохраняется(): void
    {
        $this->actingAs($this->user())
            ->postJson('/api/v1/me/cookbook/notes', ['title' => 'Пусто'])
            ->assertStatus(422);
    }

    public function test_ссылка_только_http(): void
    {
        $this->actingAs($this->user())
            ->postJson('/api/v1/me/cookbook/notes', ['title' => 'Ловушка', 'url' => 'javascript:alert(1)'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');
    }

    public function test_заметку_можно_поправить_и_удалить(): void
    {
        $owner = $this->user();
        $id = $this->actingAs($owner)
            ->postJson('/api/v1/me/cookbook/notes', ['title' => 'Пирог', 'body' => 'Мука, яйца'])
            ->json('data.id');

        $this->actingAs($owner)
            ->patchJson("/api/v1/me/cookbook/notes/{$id}", ['title' => 'Яблочный пирог', 'body' => 'Мука, яйца, яблоки'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Яблочный пирог');

        $this->actingAs($owner)->deleteJson("/api/v1/me/cookbook/notes/{$id}")->assertOk();
        $this->assertSame(0, CookbookNote::count());
    }

    public function test_гость_рецептосохранялку_не_видит(): void
    {
        $this->getJson('/api/v1/me/cookbook/notes')->assertUnauthorized();
    }

    public function test_оценки_используются_в_лучших_через_таблицу(): void
    {
        // Защита от рассинхрона: счётчики в рецепте — производные от recipe_reactions.
        $r = $this->recipe('x');
        RecipeReaction::create(['recipe_id' => $r->id, 'user_id' => $this->user()->id, 'reaction' => 'like']);

        $this->actingAs($this->user())->putJson("/api/v1/recipes/{$r->id}/reaction", ['reaction' => 'like'])->assertOk();

        $this->assertSame(2, (int) $r->fresh()->likes_count);
    }
}
