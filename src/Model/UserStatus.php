<?php

declare(strict_types=1);

namespace App\Model;

/** Hesap durumu (FR-29). Kısıtlı hesap giriş yapamaz. */
enum UserStatus: string
{
    use EnumOptions;

    case Aktif = 'aktif';
    case Kisitli = 'kisitli';

    public function label(): string
    {
        return match ($this) {
            self::Aktif => 'Aktif',
            self::Kisitli => 'Kısıtlı',
        };
    }
}
