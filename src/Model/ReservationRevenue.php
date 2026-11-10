<?php

declare(strict_types=1);

namespace App\Model;

use App\Support\DateRange;
use App\Support\Row;
use DateTimeImmutable;

/**
 * Rapor sorgusunun bir satırı (okuma modeli, tabloya yazılmaz): rezervasyon + başarılı ödeme ve iade toplamları.
 * Kaynağı: reservations ⋈ bookable_units ⋈ properties ⟕ payments (ReservationRepositoryInterface::reportRows).
 */
final class ReservationRevenue
{
    public function __construct(
        public readonly int $reservationId,
        public readonly int $unitId,
        public readonly ReservationStatus $status,
        public readonly DateTimeImmutable $checkIn,
        public readonly DateTimeImmutable $checkOut,
        public readonly int $paid,     // kuruş
        public readonly int $refunded, // kuruş
    ) {
    }

    public function range(): DateRange
    {
        return new DateRange($this->checkIn, $this->checkOut);
    }

    /** @param array<string, mixed> $r */
    public static function fromArray(array $r): self
    {
        return new self(
            Row::int($r, 'id'),
            Row::int($r, 'unit_id'),
            ReservationStatus::from(Row::string($r, 'status')),
            Row::date($r, 'check_in'),
            Row::date($r, 'check_out'),
            Row::decimal($r, 'paid'),
            Row::decimal($r, 'refunded'),
        );
    }
}
