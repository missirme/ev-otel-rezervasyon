<?php

declare(strict_types=1);

namespace App\Repository\Json;

use App\Model\Amenity;
use App\Repository\AmenityRepositoryInterface;

final class JsonAmenityRepository implements AmenityRepositoryInterface
{
    private const TABLE = 'amenities';

    public function __construct(private readonly JsonDatabase $db)
    {
    }

    public function all(): array
    {
        return array_map(Amenity::fromArray(...), $this->db->select(self::TABLE));
    }

    public function save(Amenity $amenity): Amenity
    {
        return Amenity::fromArray($this->db->insert(self::TABLE, $amenity->toArray()));
    }
}
