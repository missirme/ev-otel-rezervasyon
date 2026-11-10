<?php

declare(strict_types=1);

namespace App\Service\State;

use App\Model\ReservationStatus;

/**
 * State deseni: her rezervasyon durumu kendi sınıfıdır ve hangi durumlara
 * geçilebileceğini kendisi bilir (SRS 4.3). Tabloda olmayan her geçiş reddedilir.
 */
abstract class ReservationState
{
    abstract public function status(): ReservationStatus;

    /** Bu durum birimin gecelerini başkalarına kapatır mı? */
    abstract public function holdsNights(): bool;

    /** @return list<ReservationStatus> */
    abstract public function allowedNext(): array;

    public function canTransitionTo(ReservationStatus $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** Son durumlardan hiçbir geçiş yapılamaz. */
    public function isFinal(): bool
    {
        return $this->allowedNext() === [];
    }

    public static function of(ReservationStatus $status): self
    {
        return match ($status) {
            ReservationStatus::Beklemede => new PendingState(),
            ReservationStatus::Onaylandi => new ConfirmedState(),
            ReservationStatus::IptalEdildi => new CancelledState(),
            ReservationStatus::Reddedildi => new RejectedState(),
            ReservationStatus::SuresiDoldu => new ExpiredState(),
            ReservationStatus::Tamamlandi => new CompletedState(),
        };
    }
}
