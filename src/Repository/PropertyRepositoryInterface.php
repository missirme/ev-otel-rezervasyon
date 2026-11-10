<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Property;
use App\Model\PropertyStatus;

interface PropertyRepositoryInterface
{
    public function findById(int $id): ?Property;

    /** @return list<Property> */
    public function findByOwner(int $ownerId): array;

    /** @return list<Property> */
    public function findByStatus(PropertyStatus $status): array;

    /** @return list<Property> */
    public function all(): array;

    public function save(Property $property): Property;

    /** @return list<int> */
    public function amenityIdsOf(int $propertyId): array;

    /** @param list<int> $amenityIds */
    public function setAmenities(int $propertyId, array $amenityIds): void;
}
