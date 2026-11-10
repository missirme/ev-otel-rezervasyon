<?php

declare(strict_types=1);

namespace App\Model;

/** Konaklama türü. */
enum PropertyType: string
{
    use EnumOptions;

    case Otel = 'otel';
    case KiralikEv = 'kiralik_ev';

    public function label(): string
    {
        return match ($this) {
            self::Otel => 'Otel',
            self::KiralikEv => 'Kiralık ev',
        };
    }

    public function emoji(): string
    {
        return $this === self::Otel ? '🏨' : '🏠';
    }
}
