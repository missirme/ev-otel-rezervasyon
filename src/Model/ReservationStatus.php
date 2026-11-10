<?php

declare(strict_types=1);

namespace App\Model;

/** Rezervasyon durumları (SRS 4.3). İzinli geçişler State desenindeki sınıflardadır. */
enum ReservationStatus: string
{
    use EnumOptions;

    case Beklemede = 'beklemede';
    case Onaylandi = 'onaylandi';
    case IptalEdildi = 'iptal_edildi';
    case Reddedildi = 'reddedildi';
    case SuresiDoldu = 'suresi_doldu';
    case Tamamlandi = 'tamamlandi';

    public function label(): string
    {
        return match ($this) {
            self::Beklemede => 'Beklemede',
            self::Onaylandi => 'Onaylandı',
            self::IptalEdildi => 'İptal Edildi',
            self::Reddedildi => 'Reddedildi',
            self::SuresiDoldu => 'Süresi Doldu',
            self::Tamamlandi => 'Tamamlandı',
        };
    }

    /** Bootstrap rozet rengi */
    public function badge(): string
    {
        return match ($this) {
            self::Beklemede => 'warning',
            self::Onaylandi => 'success',
            self::Tamamlandi => 'primary',
            self::IptalEdildi, self::Reddedildi => 'danger',
            self::SuresiDoldu => 'secondary',
        };
    }
}
