<?php

declare(strict_types=1);

/**
 * Ekrana basılan her veri bu fonksiyondan geçer (XSS koruması, NFR-14).
 */
function e(mixed $value): string
{
    if ($value instanceof BackedEnum) {
        $value = $value->value;
    }

    return htmlspecialchars(is_scalar($value) || $value instanceof Stringable ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
