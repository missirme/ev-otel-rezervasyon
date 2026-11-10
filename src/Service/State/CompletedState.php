<?php

declare(strict_types=1);

namespace App\Service\State;

use App\Model\ReservationStatus;

/** Tamamlandı (son durum): çıkış tarihi geçti; geceler geçmişte kaldığı için gece kayıtları silinir. */
final class CompletedState extends ReservationState
{
    public function status(): ReservationStatus
    {
        return ReservationStatus::Tamamlandi;
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
