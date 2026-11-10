<?php

declare(strict_types=1);

namespace App\Model;

/** Rezervasyon kalemi türü (fiyat anlık görüntüsü, BR-16). */
enum ChargeType: string
{
    use EnumOptions;

    case KonaklamaGecesi = 'konaklama_gecesi';
    case Indirim = 'indirim';
    case Vergi = 'vergi';
    case EkUcret = 'ek_ucret';

    public function label(): string
    {
        return match ($this) {
            self::KonaklamaGecesi => 'Konaklama gecesi',
            self::Indirim => 'İndirim',
            self::Vergi => 'Vergi',
            self::EkUcret => 'Ek ücret',
        };
    }
}
