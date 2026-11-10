<?php

declare(strict_types=1);

namespace App\Model;

/** İlan durumu. Silme mantıksaldır: durum = kaldırıldı (FR-21, SRS 7.1). */
enum PropertyStatus: string
{
    use EnumOptions;

    case Taslak = 'taslak';
    case Aktif = 'aktif';
    case Pasif = 'pasif';
    case Kaldirildi = 'kaldirildi';

    public function label(): string
    {
        return match ($this) {
            self::Taslak => 'Taslak',
            self::Aktif => 'Aktif',
            self::Pasif => 'Pasif',
            self::Kaldirildi => 'Kaldırıldı',
        };
    }
}
