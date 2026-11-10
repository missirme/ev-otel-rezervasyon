<?php

declare(strict_types=1);

namespace App\Model;

use App\Support\Row;
use DateTimeImmutable;

/**
 * Engelleyen rezervasyonun tuttuğu gece. (unit_id, night_date) benzersizdir:
 * çift rezervasyona karşı veri düzeyindeki son savunma (SRS 4.5, 7.1).
 */
final class ReservationNight
{
    public function __construct(
        public int $reservationId,
        public int $unitId,
        public DateTimeImmutable $nightDate,
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromArray(array $r): self
    {
        return new self(Row::int($r, 'reservation_id'), Row::int($r, 'unit_id'), Row::date($r, 'night_date'));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'reservation_id' => $this->reservationId,
            'unit_id' => $this->unitId,
            'night_date' => Row::fmtDate($this->nightDate),
        ];
    }
}
