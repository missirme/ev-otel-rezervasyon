<?php

declare(strict_types=1);

namespace App\Repository\Json;

use App\Model\BookableUnit;
use App\Repository\BookableUnitRepositoryInterface;

final class JsonBookableUnitRepository implements BookableUnitRepositoryInterface
{
    private const TABLE = 'bookable_units';

    public function __construct(private readonly JsonDatabase $db)
    {
    }

    public function findById(int $id): ?BookableUnit
    {
        $row = $this->db->first(self::TABLE, fn (array $r): bool => $r['id'] === $id);

        return $row === null ? null : BookableUnit::fromArray($row);
    }

    public function findByProperty(int $propertyId): array
    {
        return array_map(
            BookableUnit::fromArray(...),
            $this->db->select(self::TABLE, fn (array $r): bool => $r['property_id'] === $propertyId)
        );
    }

    public function all(): array
    {
        return array_map(BookableUnit::fromArray(...), $this->db->select(self::TABLE));
    }

    public function save(BookableUnit $unit): BookableUnit
    {
        if ($unit->id === null) {
            return BookableUnit::fromArray($this->db->insert(self::TABLE, $unit->toArray()));
        }
        $id = $unit->id;
        $this->db->update(self::TABLE, fn (array $r): bool => $r['id'] === $id, $unit->toArray());

        return $unit;
    }
}
