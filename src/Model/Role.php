<?php

declare(strict_types=1);

namespace App\Model;

/** Kullanıcı rolü (FR-05). Yetki her istekte sunucu tarafında denetlenir. */
enum Role: string
{
    use EnumOptions;

    case Musteri = 'musteri';
    case IlanSahibi = 'ilan_sahibi';
    case Yonetici = 'yonetici';

    public function label(): string
    {
        return match ($this) {
            self::Musteri => 'Müşteri',
            self::IlanSahibi => 'İlan sahibi',
            self::Yonetici => 'Yönetici',
        };
    }
}
