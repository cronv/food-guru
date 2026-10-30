<?php

namespace Tests\Feature;

use App\Enums\RatingLevel;
use App\Enums\RatingSource;
use App\Services\RatingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RatingServiceTest extends TestCase
{
    use RefreshDatabase;

    private RatingService $rating;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rating = app(RatingService::class);
    }

    public function test_один_и_тот_же_повод_начисляется_только_раз(): void
    {
        $user = $this->user();

        $first = $this->rating->award($user, RatingSource::Comment, null, 'comment:1:1');
        $second = $this->rating->award($user, RatingSource::Comment, null, 'comment:1:1');

        $this->assertTrue($first);
        $this->assertFalse($second, 'Повтор по тому же ключу не должен начислять');
        $this->assertSame(RatingSource::Comment->points(), $user->fresh()->rating_points);
        $this->assertSame(1, DB::table('rating_events')->count());
    }

    public function test_без_ключа_однократности_начисляется_каждый_раз(): void
    {
        $user = $this->user();

        $this->rating->award($user, RatingSource::Comment);
        $this->rating->award($user, RatingSource::Comment);

        $this->assertSame(RatingSource::Comment->points() * 2, $user->fresh()->rating_points);
    }

    public function test_уровень_поднимается_при_переходе_через_порог(): void
    {
        $user = $this->user();

        $this->assertSame(RatingLevel::Novice, $user->rating_level);

        // 4 рецепта по 25 баллов — ровно порог «Повара».
        for ($i = 1; $i <= 4; $i++) {
            $this->rating->award($user, RatingSource::Recipe, null, "recipe:{$i}");
        }

        $user->refresh();

        $this->assertSame(100, $user->rating_points);
        $this->assertSame(RatingLevel::Cook, $user->rating_level);
    }

    public function test_повторный_заход_в_тот_же_день_не_добавляет_балл(): void
    {
        $user = $this->user();

        $this->rating->registerVisit($user);
        $pointsAfterFirst = $user->fresh()->rating_points;

        $this->rating->registerVisit($user->fresh());

        $this->assertSame($pointsAfterFirst, $user->fresh()->rating_points);
        $this->assertSame(1, $user->fresh()->visits_total);
    }

    public function test_заход_подряд_продолжает_серию_а_пропуск_её_обнуляет(): void
    {
        $user = $this->user();

        $this->travelTo(now()->startOfDay());
        $this->rating->registerVisit($user);
        $this->assertSame(1, $user->fresh()->streak_days);

        $this->travel(1)->days();
        $this->rating->registerVisit($user->fresh());
        $this->assertSame(2, $user->fresh()->streak_days);

        // Пропускаем день.
        $this->travel(2)->days();
        $this->rating->registerVisit($user->fresh());

        $this->assertSame(1, $user->fresh()->streak_days, 'Пропуск дня обнуляет серию');
        $this->assertSame(2, $user->fresh()->streak_best, 'Лучшая серия сохраняется');

        $this->travelBack();
    }

    public function test_за_полную_неделю_без_пропусков_даётся_бонус(): void
    {
        $user = $this->user();
        $this->travelTo(now()->startOfDay());

        for ($day = 0; $day < 7; $day++) {
            $this->rating->registerVisit($user->fresh());
            $this->travel(1)->days();
        }

        $user->refresh();

        $this->assertSame(7, $user->streak_days);
        $this->assertSame(
            RatingSource::Visit->points() * 7 + RatingSource::Streak->points(),
            $user->rating_points,
        );

        $this->travelBack();
    }

    public function test_пересчёт_восстанавливает_счётчик_из_журнала(): void
    {
        $user = $this->user();

        $this->rating->award($user, RatingSource::Recipe, null, 'recipe:9');
        // Счётчик «разъехался» с журналом — так бывает после ручных правок.
        $user->forceFill(['rating_points' => 0, 'rating_level' => RatingLevel::Novice])->save();

        $this->rating->recalculate($user);

        $this->assertSame(RatingSource::Recipe->points(), $user->fresh()->rating_points);
    }

    public function test_разбор_баллов_перечисляет_все_источники(): void
    {
        $user = $this->user();
        $this->rating->award($user, RatingSource::Comment, null, 'c:1');

        $breakdown = collect($this->rating->breakdown($user))->keyBy('source');

        $this->assertCount(count(RatingSource::cases()), $breakdown);
        $this->assertSame(1, $breakdown['comment']['count']);
        $this->assertSame(0, $breakdown['recipe']['count']);
    }
}
