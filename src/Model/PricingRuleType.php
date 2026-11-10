<?php

declare(strict_types=1);

namespace App\Model;

/** Vergi / ek ücret kuralı türü. */
enum PricingRuleType: string
{
    use EnumOptions;

    case Vergi = 'vergi';
    case EkUcret = 'ek_ucret';

    public function label(): string
    {
        return match ($this) {
            self::Vergi => 'Vergi',
            self::EkUcret => 'Ek ücret',
        };
    }
}
