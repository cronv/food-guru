<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\Slug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Эталоны — результат прежней реализации (транслитерация в ASCII, затем slug).
 * Расхождение означало бы дубли тегов и смену адресов рецептов после переезда.
 */
final class SlugTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function reference(): iterable
    {
        yield 'тег на ужин' => ['На ужин', 'na-uzin'];
        yield 'тег без глютена' => ['Без глютена', 'bez-gliutena'];
        yield 'тег шоколад' => ['Шоколад', 'sokolad'];
        yield 'длинное название' => ['Тыквенный крем-суп с хрустящим нутом', 'tykvennyi-krem-sup-s-xrustiashhim-nutom'];
        yield 'тире и знаки' => ['Щи да каша — пища наша!', 'shhi-da-kasa-pishha-nasa'];
        yield 'ё' => ['Ёжик в тумане', 'ezik-v-tumane'];
        yield 'дефис внутри' => ['Хачапури по-аджарски', 'xacapuri-po-adzarski'];
        yield 'амперсанд и @' => ['Сыр & вино @ дома', 'syr-vino-at-doma'];
        yield 'подчёркивания' => ['Борщ_с_говядиной', 'borshh-s-goviadinoi'];
        yield 'пусто' => ['   ', ''];
        yield 'эмодзи и точка' => ['🍕 Пицца 2.0', 'picca-20'];
    }

    #[DataProvider('reference')]
    public function testSlugMatchesReference(string $title, string $expected): void
    {
        self::assertSame($expected, Slug::make($title));
    }
}
