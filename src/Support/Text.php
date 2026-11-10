<?php

declare(strict_types=1);

namespace App\Support;

/** Türkçe karakterlere duyarlı küçük harf ve arama yardımcıları. */
final class Text
{
    public static function lower(string $text): string
    {
        return mb_strtolower(strtr($text, ['I' => 'ı', 'İ' => 'i']), 'UTF-8');
    }

    public static function contains(string $haystack, string $needle): bool
    {
        $needle = trim($needle);

        return $needle === '' || str_contains(self::lower($haystack), self::lower($needle));
    }

    public static function length(string $text): int
    {
        return mb_strlen($text, 'UTF-8');
    }
}
