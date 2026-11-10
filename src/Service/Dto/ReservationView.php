<?php

declare(strict_types=1);

namespace App\Service\Dto;

use App\Model\BookableUnit;
use App\Model\Property;
use App\Model\Reservation;
use App\Model\ReservationCharge;
use App\Model\Review;
use App\Model\User;

/** Rezervasyonun ekranda gösterilen bütün hâli (rezervasyon + birim + ilan + taraflar). */
final class ReservationView
{
    /** @param list<ReservationCharge> $charges */
    public function __construct(
        public readonly Reservation $reservation,
        public readonly BookableUnit $unit,
        public readonly Property $property,
        public readonly ?User $customer,
        public readonly ?User $host,
        public readonly array $charges = [],
        public readonly int $paid = 0,
        public readonly int $refunded = 0,
        public readonly ?Review $review = null,
        public readonly bool $canPay = false,
        public readonly bool $canCancel = false,
        public readonly bool $canReview = false,
        public readonly bool $isHold = false,
    ) {
    }
}
