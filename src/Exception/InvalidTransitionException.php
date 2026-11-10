<?php

declare(strict_types=1);

namespace App\Exception;

use App\Model\ReservationStatus;

/** Durum makinesinde izin verilmeyen geçiş (FR-15). Denetim kaydına yazılır (FR-41). */
class InvalidTransitionException extends AppException
{
    public function __construct(
        public readonly int $reservationId,
        public readonly ReservationStatus $from,
        public readonly ReservationStatus $to,
        public readonly ?int $actorId,
    ) {
        parent::__construct(sprintf(
            'Rezervasyon "%s" durumundan "%s" durumuna geçirilemez.',
            $from->label(),
            $to->label()
        ));
    }
}
