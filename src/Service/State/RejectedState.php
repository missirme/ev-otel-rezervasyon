<?php

declare(strict_types=1);

namespace App\Service\State;

use App\Model\ReservationStatus;

/** Reddedildi (son durum): ilan sahibi onaylı moddaki talebi reddetti. */
final class RejectedState extends ReservationState
{
    public function status(): ReservationStatus
    {
        return ReservationStatus::Reddedildi;
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
