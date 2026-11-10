<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Amenity;

interface AmenityRepositoryInterface
{
    /** @return list<Amenity> */
    public function all(): array;

    public function save(Amenity $amenity): Amenity;
}
