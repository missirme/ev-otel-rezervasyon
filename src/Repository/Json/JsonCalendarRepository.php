<?php

declare(strict_types=1);

namespace App\Repository\Json;

use App\Model\CalendarEntry;
use App\Repository\CalendarRepositoryInterface;
use DateTimeImmutable;

final class JsonCalendarRepository implements CalendarRepositoryInterface
{
    private const TABLE = 'calendar_entries';

    public function __construct(private readonly JsonDatabase $db)
    {
    }

    public function findForUnit(int $unitId, DateTimeImmutable $from, DateTimeImmutable $toExclusive): array
    {
        $start = $from->format('Y-m-d');
        $end = $toExclusive->format('Y-m-d');
        $entries = [];
        foreach (
            $this->db->select(
                self::TABLE,
                fn (array $r): bool => $r['unit_id'] === $unitId && $r['date'] >= $start && $r['date'] < $end
            ) as $row
        ) {
            $entry = CalendarEntry::fromArray($row);
            $entries[$entry->date->format('Y-m-d')] = $entry;
        }

        return $entries;
    }

    public function upsert(CalendarEntry $entry): CalendarEntry
    {
        $unitId = $entry->unitId;
        $date = $entry->date->format('Y-m-d');
        $existing = $this->db->first(self::TABLE, fn (array $r): bool => $r['unit_id'] === $unitId && $r['date'] === $date);
        if ($existing === null) {
            $data = $entry->toArray();
            $data['id'] = null;

            return CalendarEntry::fromArray($this->db->insert(self::TABLE, $data));
        }
        $data = $entry->toArray();
        $data['id'] = $existing['id'];
        $this->db->update(self::TABLE, fn (array $r): bool => $r['id'] === $existing['id'], $data);

        return CalendarEntry::fromArray($data);
    }

    public function deleteForUnitDate(int $unitId, DateTimeImmutable $date): void
    {
        $day = $date->format('Y-m-d');
        $this->db->delete(self::TABLE, fn (array $r): bool => $r['unit_id'] === $unitId && $r['date'] === $day);
    }
}
