<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\BookableUnit;

interface BookableUnitRepositoryInterface
{
    public function findById(int $id): ?BookableUnit;

    /** @return list<BookableUnit> */
    public function findByProperty(int $propertyId): array;

    /** @return list<BookableUnit> */
    public function all(): array;

    public function save(BookableUnit $unit): BookableUnit;
}
