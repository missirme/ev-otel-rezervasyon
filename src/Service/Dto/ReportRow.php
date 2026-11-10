<?php

declare(strict_types=1);

namespace App\Service\Dto;

/** Rapor satırı: doluluk (baz puan, 4567 = %45,67) ve net gelir (kuruş). */
final class ReportRow
{
    public function __construct(
        public readonly string $label,
        public readonly int $activeUnits,
        public readonly int $capacityNights,
        public readonly int $occupiedNights,
        public readonly int $occupancyBp,
        public readonly int $reservationCount,
        public readonly int $grossRevenue,
        public readonly int $refunds,
        public readonly int $netRevenue,
    ) {
    }
}
