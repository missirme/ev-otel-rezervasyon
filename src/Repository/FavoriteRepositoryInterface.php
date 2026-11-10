<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Favorite;

interface FavoriteRepositoryInterface
{
    public function exists(int $userId, int $propertyId): bool;

    public function add(Favorite $favorite): void;

    public function remove(int $userId, int $propertyId): void;

    /** @return list<Favorite> */
    public function findByUser(int $userId): array;
}
