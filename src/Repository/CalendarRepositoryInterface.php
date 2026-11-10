<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\CalendarEntry;
use DateTimeImmutable;

interface CalendarRepositoryInterface
{
    /**
     * [from, toExclusive) aralığındaki takvim istisnaları, "Y-m-d" anahtarıyla.
     *
     * @return array<string, CalendarEntry>
     */
    public function findForUnit(int $unitId, DateTimeImmutable $from, DateTimeImmutable $toExclusive): array;

    /** (birim, tarih) benzersizdir: kayıt varsa günceller, yoksa ekler. */
    public function upsert(CalendarEntry $entry): CalendarEntry;

    public function deleteForUnitDate(int $unitId, DateTimeImmutable $date): void;
}
