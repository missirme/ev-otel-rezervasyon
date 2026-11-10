<?php

declare(strict_types=1);

namespace App\Model;

/** Yorum denetim durumu (FR-25). */
enum ModerationStatus: string
{
    use EnumOptions;

    case Yayinda = 'yayinda';
    case Incelemede = 'incelemede';
    case Gizlendi = 'gizlendi';

    public function label(): string
    {
        return match ($this) {
            self::Yayinda => 'Yayında',
            self::Incelemede => 'İncelemede',
            self::Gizlendi => 'Gizlendi',
        };
    }
}
