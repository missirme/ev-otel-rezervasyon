<?php

declare(strict_types=1);

namespace App\Service\State;

use App\Model\ReservationStatus;

/** Onaylandı: kesinleşmiş rezervasyon; geceleri tutar. */
final class ConfirmedState extends ReservationState
{
    public function status(): ReservationStatus
    {
        return ReservationStatus::Onaylandi;
    }

    public function holdsNights(): bool
    {
        return true;
    }

    public function allowedNext(): array
    {
        return [ReservationStatus::IptalEdildi, ReservationStatus::Tamamlandi];
    }
}
