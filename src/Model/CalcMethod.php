<?php

declare(strict_types=1);

namespace App\Model;

/** Vergi / ek ücret hesaplama yöntemi. */
enum CalcMethod: string
{
    use EnumOptions;

    case Yuzde = 'yuzde';
    case GecelikSabit = 'gecelik_sabit';
    case RezervasyonBasinaSabit = 'rezervasyon_basina_sabit';

    public function label(): string
    {
        return match ($this) {
            self::Yuzde => 'Yüzde (indirimli konaklama tutarı üzerinden)',
            self::GecelikSabit => 'Gecelik sabit',
            self::RezervasyonBasinaSabit => 'Rezervasyon başına sabit',
        };
    }
}
