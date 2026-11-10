<?php

declare(strict_types=1);

namespace App\Model;

/** Takvim istisnası durumu (FR-22). */
enum CalendarStatus: string
{
    use EnumOptions;

    case Acik = 'acik';
    case Kapali = 'kapali';

    public function label(): string
    {
        return match ($this) {
            self::Acik => 'Açık',
            self::Kapali => 'Kapalı',
        };
    }
}
