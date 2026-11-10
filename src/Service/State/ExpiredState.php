<?php

declare(strict_types=1);

namespace App\Service\State;

use App\Model\ReservationStatus;

/** Süresi Doldu (son durum): bekletme süresinde ödeme/onay gerçekleşmedi. */
final class ExpiredState extends ReservationState
{
    public function status(): ReservationStatus
    {
        return ReservationStatus::SuresiDoldu;
    }

    public function holdsNights(): bool
    {
        return false;
    }

    public function allowedNext(): array
    {
        return [];
    }
}
