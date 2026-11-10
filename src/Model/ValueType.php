<?php

declare(strict_types=1);

namespace App\Model;

/** Kural değerinin türü. */
enum ValueType: string
{
    use EnumOptions;

    case Yuzde = 'yuzde';
    case Sabit = 'sabit';

    public function label(): string
    {
        return match ($this) {
            self::Yuzde => 'Yüzde',
            self::Sabit => 'Sabit tutar',
        };
    }
}
