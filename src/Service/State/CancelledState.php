<?php

declare(strict_types=1);

namespace App\Service\State;

use App\Model\ReservationStatus;

/** İptal Edildi (son durum): geceler serbesttir. */
final class CancelledState extends ReservationState
{
    public function status(): ReservationStatus
    {
        return ReservationStatus::IptalEdildi;
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
