<?php

declare(strict_types=1);

namespace App\Model;

/** Rezervasyon modu (FR-38, BR-05). */
enum BookingMode: string
{
    use EnumOptions;

    case Aninda = 'aninda';
    case Onayli = 'onayli';

    public function label(): string
    {
        return match ($this) {
            self::Aninda => 'Anında onay (ödeme ile)',
            self::Onayli => 'İlan sahibi onaylı',
        };
    }
}
