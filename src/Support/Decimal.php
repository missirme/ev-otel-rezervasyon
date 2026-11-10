<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * DECIMAL(10,2) değerlerini tam sayıya (100 katı) çevirir.
 * Para için kuruş, yüzde için baz puan elde edilir: "1560.00" -> 156000, "30" -> 3000.
 * Float kullanılmadığı için yuvarlama hatası olmaz (BR-25).
 */
final class Decimal
{
    public static function toInt(string|int $value): int
    {
        if (is_int($value)) {
            return $value * 100;
        }
        $v = str_replace(',', '.', trim($value));
        if (!preg_match('/^(-?)(\d{1,10})(?:\.(\d{1,2}))?$/', $v, $m)) {
            throw new InvalidArgumentException('Geçersiz sayı: ' . $value);
        }
        $result = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '0', 2, '0');

        return $m[1] === '-' ? -$result : $result;
    }

    public static function fromInt(int $value): string
    {
        $sign = $value < 0 ? '-' : '';
        $abs = abs($value);

        return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function isValid(string $value): bool
    {
        try {
            self::toInt($value);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
