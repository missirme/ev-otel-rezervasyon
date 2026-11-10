<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/** Türkçe gün/ay adları (intl eklentisine bağımlı olmamak için). */
final class Dates
{
    private const DAYS = ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];
    private const DAYS_SHORT = ['Pt', 'Sa', 'Ça', 'Pe', 'Cu', 'Ct', 'Pz'];
    private const MONTHS = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

    public static function dayName(DateTimeImmutable $date): string
    {
        return self::DAYS[(int) $date->format('N') - 1];
    }

    /** @return list<string> */
    public static function shortDayNames(): array
    {
        return self::DAYS_SHORT;
    }

    public static function monthName(int $month): string
    {
        return self::MONTHS[$month - 1];
    }

    /** 10 Kasım 2026 */
    public static function long(DateTimeImmutable $date): string
    {
        return $date->format('j') . ' ' . self::monthName((int) $date->format('n')) . ' ' . $date->format('Y');
    }

    /** Kalan gün = giriş tarihi - bugün (saat dikkate alınmaz, SRS 4.11). */
    public static function daysBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return (int) $from->setTime(0, 0)->diff($to->setTime(0, 0))->format('%r%a');
    }
}
