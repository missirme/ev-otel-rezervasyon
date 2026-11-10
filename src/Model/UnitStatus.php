<?php

declare(strict_types=1);

namespace App\Model;

/** Birim (oda/ev) durumu. */
enum UnitStatus: string
{
    use EnumOptions;

    case Aktif = 'aktif';
    case Pasif = 'pasif';

    public function label(): string
    {
        return match ($this) {
            self::Aktif => 'Aktif',
            self::Pasif => 'Pasif',
        };
    }
}
