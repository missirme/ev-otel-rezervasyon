<?php

declare(strict_types=1);

namespace App\Model;

/** Fiyat kuralı türü (FR-43, SRS 4.10). */
enum PriceRuleType: string
{
    use EnumOptions;

    case Sezon = 'sezon';
    case HaftaSonu = 'hafta_sonu';
    case UzunKonaklama = 'uzun_konaklama';

    public function label(): string
    {
        return match ($this) {
            self::Sezon => 'Sezon farkı',
            self::HaftaSonu => 'Hafta sonu farkı',
            self::UzunKonaklama => 'Uzun konaklama indirimi',
        };
    }
}
