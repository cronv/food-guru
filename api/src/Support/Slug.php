<?php

declare(strict_types=1);

namespace App\Support;

use voku\helper\ASCII;

/**
 * Адреса (slug) — побайтно как прежде (транслитерация в ASCII, затем slug). Транслитерация
 * та же библиотека voku/portable-ascii, что раньше: у AsciiSlugger Symfony
 * она другая («ужин» → uzhin, а не uzin), и сохранённый рецепт с тегом
 * «на ужин» не нашёл бы существующий тег na-uzin и завёл бы дубль.
 */
final class Slug
{
    public static function ascii(string $value): string
    {
        return ASCII::to_ascii($value, 'en', replace_single_chars_only: false);
    }

    public static function make(string $title): string
    {
        $title = self::ascii($title);
        $title = (string) preg_replace('![_]+!u', '-', $title);
        $title = str_replace('@', '-at-', $title);
        $title = (string) preg_replace('![^\-\pL\pN\s]+!u', '', mb_strtolower($title, 'UTF-8'));
        $title = (string) preg_replace('![\-\s]+!u', '-', $title);

        return trim($title, '-');
    }
}
