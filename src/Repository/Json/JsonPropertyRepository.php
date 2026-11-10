<?php

declare(strict_types=1);

namespace App\Repository\Json;

use App\Model\Property;
use App\Model\PropertyStatus;
use App\Repository\PropertyRepositoryInterface;

final class JsonPropertyRepository implements PropertyRepositoryInterface
{
    private const TABLE = 'properties';
    private const LINK_TABLE = 'property_amenities';

    public function __construct(private readonly JsonDatabase $db)
    {
    }

    public function findById(int $id): ?Property
    {
        $row = $this->db->first(self::TABLE, fn (array $r): bool => $r['id'] === $id);

        return $row === null ? null : Property::fromArray($row);
    }

    public function findByOwner(int $ownerId): array
    {
        return array_map(
            Property::fromArray(...),
            $this->db->select(self::TABLE, fn (array $r): bool => $r['owner_id'] === $ownerId)
        );
    }

    public function findByStatus(PropertyStatus $status): array
    {
        return array_map(
            Property::fromArray(...),
            $this->db->select(self::TABLE, fn (array $r): bool => $r['status'] === $status->value)
        );
    }

    public function all(): array
    {
        return array_map(Property::fromArray(...), $this->db->select(self::TABLE));
    }

    public function save(Property $property): Property
    {
        if ($property->id === null) {
            return Property::fromArray($this->db->insert(self::TABLE, $property->toArray()));
        }
        $id = $property->id;
        $this->db->update(self::TABLE, fn (array $r): bool => $r['id'] === $id, $property->toArray());

        return $property;
    }

    public function amenityIdsOf(int $propertyId): array
    {
        $ids = [];
        foreach ($this->db->select(self::LINK_TABLE, fn (array $r): bool => $r['property_id'] === $propertyId) as $row) {
            $ids[] = (int) $row['amenity_id'];
        }
        sort($ids);

        return $ids;
    }

    public function setAmenities(int $propertyId, array $amenityIds): void
    {
        $this->db->transaction(function () use ($propertyId, $amenityIds): void {
            $this->db->delete(self::LINK_TABLE, fn (array $r): bool => $r['property_id'] === $propertyId);
            foreach (array_unique($amenityIds) as $amenityId) {
                $this->db->insert(self::LINK_TABLE, ['property_id' => $propertyId, 'amenity_id' => $amenityId]);
            }
        });
    }
}
