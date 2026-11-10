<?php

declare(strict_types=1);

namespace App\Model;

/** İptal politikası (BR-17). Rezervasyonda anlık görüntü olarak saklanır (BR-29). */
enum CancellationPolicy: string
{
    use EnumOptions;

    case Esnek = 'esnek';
    case Orta = 'orta';
    case Kati = 'kati';

    public function label(): string
    {
        return match ($this) {
            self::Esnek => 'Esnek',
            self::Orta => 'Orta',
            self::Kati => 'Katı',
        };
    }
}
