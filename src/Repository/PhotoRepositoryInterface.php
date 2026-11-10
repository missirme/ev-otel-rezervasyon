<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Photo;

interface PhotoRepositoryInterface
{
    public function findById(int $id): ?Photo;

    /**
     * Sıralama bilgisine göre.
     *
     * @return list<Photo>
     */
    public function findByProperty(int $propertyId): array;

    public function save(Photo $photo): Photo;

    public function delete(int $id): void;
}
