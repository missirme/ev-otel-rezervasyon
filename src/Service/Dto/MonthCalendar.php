<?php

declare(strict_types=1);

namespace App\Service\Dto;

use DateTimeImmutable;

/**
 * Aylık müsaitlik takvimi (FR-10, AC-32). Haftalar pazartesiden başlar.
 * Her gün: ['date' => DateTimeImmutable, 'status' => musait|dolu|kapali|gecmis, 'price' => ?int]
 */
final class MonthCalendar
{
    /**
     * @param list<list<array{date: DateTimeImmutable, status: string, price: ?int}|null>> $weeks
     */
    public function __construct(
        public readonly int $unitId,
        public readonly int $year,
        public readonly int $month,
        public readonly string $title,
        public readonly string $previous,
        public readonly string $next,
        public readonly array $weeks,
    ) {
    }
}
