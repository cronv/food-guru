<?php

namespace Tests\Unit;

use App\Enums\RatingLevel;
use App\Enums\RatingSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RatingLevelTest extends TestCase
{
    public static function границы(): array
    {
        return [
            'ноль баллов' => [0, RatingLevel::Novice],
            'на балл ниже повара' => [99, RatingLevel::Novice],
            'ровно порог повара' => [100, RatingLevel::Cook],
            'на балл ниже шефа' => [399, RatingLevel::Cook],
            'ровно порог шефа' => [400, RatingLevel::Chef],
            'на балл ниже гуру' => [1199, RatingLevel::Chef],
            'ровно порог гуру' => [1200, RatingLevel::Guru],
            'много сверх гуру' => [99999, RatingLevel::Guru],
        ];
    }

    #[DataProvider('границы')]
    public function test_уровень_определяется_по_порогу(int $points, RatingLevel $expected): void
    {
        $this->assertSame($expected, RatingLevel::fromPoints($points));
    }

    public function test_у_верхнего_уровня_нет_следующего(): void
    {
        $this->assertNull(RatingLevel::Guru->next());
        $this->assertSame(RatingLevel::Cook, RatingLevel::Novice->next());
    }

    public function test_фотоотчёт_дороже_обычного_отзыва(): void
    {
        $this->assertGreaterThan(
            RatingSource::Comment->points(),
            RatingSource::Comment->points() + RatingSource::PhotoReport->points(),
        );
    }
}
