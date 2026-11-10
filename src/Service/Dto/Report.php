<?php

declare(strict_types=1);

namespace App\Service\Dto;

use DateTimeImmutable;

/** Doluluk ve gelir raporu (FR-30, BR-30). */
final class Report
{
    /**
     * @param array<string, int> $statusCounts durum etiketi => adet
     * @param list<ReportRow> $byProperty
     * @param list<ReportRow> $byUnit
     * @param list<ReportRow> $byRoomType
     * @param list<ReportRow> $byMonth
     */
    public function __construct(
        public readonly DateTimeImmutable $from,
        public readonly DateTimeImmutable $to,
        public readonly int $periodNights,
        public readonly ReportRow $total,
        public readonly array $statusCounts,
        public readonly array $byProperty,
        public readonly array $byUnit,
        public readonly array $byRoomType,
        public readonly array $byMonth,
    ) {
    }
}
