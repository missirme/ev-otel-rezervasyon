<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * JSON/veritabanı satırını (dizi) model alanlarına çeviren yardımcılar.
 * Tarih "Y-m-d", tarih-saat "Y-m-d H:i:s", para "1560.00" biçimindedir.
 */
final class Row
{
    /** @param array<string, mixed> $row */
    public static function int(array $row, string $key): int
    {
        $v = $row[$key] ?? null;
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (int) $v;
        }
        throw new InvalidArgumentException("Eksik veya geçersiz tam sayı alanı: {$key}");
    }

    /** @param array<string, mixed> $row */
    public static function nullableInt(array $row, string $key): ?int
    {
        return ($row[$key] ?? null) === null ? null : self::int($row, $key);
    }

    /** @param array<string, mixed> $row */
    public static function string(array $row, string $key): string
    {
        $v = $row[$key] ?? null;
        if (is_string($v)) {
            return $v;
        }
        if (is_int($v)) {
            return (string) $v;
        }
        throw new InvalidArgumentException("Eksik metin alanı: {$key}");
    }

    /** @param array<string, mixed> $row */
    public static function nullableString(array $row, string $key): ?string
    {
        return ($row[$key] ?? null) === null ? null : self::string($row, $key);
    }

    /** @param array<string, mixed> $row */
    public static function bool(array $row, string $key): bool
    {
        return (bool) ($row[$key] ?? false);
    }

    /** @param array<string, mixed> $row */
    public static function date(array $row, string $key): DateTimeImmutable
    {
        return DateRange::parseDate(self::string($row, $key));
    }

    /** @param array<string, mixed> $row */
    public static function nullableDate(array $row, string $key): ?DateTimeImmutable
    {
        return ($row[$key] ?? null) === null ? null : self::date($row, $key);
    }

    /** @param array<string, mixed> $row */
    public static function dateTime(array $row, string $key): DateTimeImmutable
    {
        $value = self::string($row, $key);
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
        if ($dt === false) {
            throw new InvalidArgumentException("Geçersiz tarih-saat alanı: {$key}");
        }

        return $dt;
    }

    /** @param array<string, mixed> $row */
    public static function nullableDateTime(array $row, string $key): ?DateTimeImmutable
    {
        return ($row[$key] ?? null) === null ? null : self::dateTime($row, $key);
    }

    /**
     * DECIMAL(10,2) metnini x100 tam sayıya çevirir (kuruş / baz puan).
     *
     * @param array<string, mixed> $row
     */
    public static function decimal(array $row, string $key): int
    {
        return Decimal::toInt(self::string($row, $key));
    }

    /** @param array<string, mixed> $row */
    public static function nullableDecimal(array $row, string $key): ?int
    {
        return ($row[$key] ?? null) === null ? null : self::decimal($row, $key);
    }

    public static function fmtDate(?DateTimeImmutable $date): ?string
    {
        return $date?->format('Y-m-d');
    }

    public static function fmtDateTime(?DateTimeImmutable $dateTime): ?string
    {
        return $dateTime?->format('Y-m-d H:i:s');
    }

    public static function fmtDecimal(?int $value): ?string
    {
        return $value === null ? null : Decimal::fromInt($value);
    }
}
