<?php

declare(strict_types=1);

namespace App\Service\State;

use App\Model\ReservationStatus;

/** Beklemede: ödeme (anında) veya ilan sahibi onayı (onaylı) bekleniyor; süre dolana kadar geceleri tutar. */
final class PendingState extends ReservationState
{
    public function status(): ReservationStatus
    {
        return ReservationStatus::Beklemede;
    }

    public function holdsNights(): bool
    {
        return true;
    }

    public function allowedNext(): array
    {
        return [
            ReservationStatus::Onaylandi,
            ReservationStatus::Reddedildi,
            ReservationStatus::IptalEdildi,
            ReservationStatus::SuresiDoldu,
        ];
    }
}
