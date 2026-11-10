<?php

declare(strict_types=1);

namespace App\Repository\Json;

use App\Model\Favorite;
use App\Repository\FavoriteRepositoryInterface;

final class JsonFavoriteRepository implements FavoriteRepositoryInterface
{
    private const TABLE = 'favorites';

    public function __construct(private readonly JsonDatabase $db)
    {
    }

    public function exists(int $userId, int $propertyId): bool
    {
        return $this->db->first(
            self::TABLE,
            fn (array $r): bool => $r['user_id'] === $userId && $r['property_id'] === $propertyId
        ) !== null;
    }

    public function add(Favorite $favorite): void
    {
        $this->db->insert(self::TABLE, $favorite->toArray());
    }

    public function remove(int $userId, int $propertyId): void
    {
        $this->db->delete(self::TABLE, fn (array $r): bool => $r['user_id'] === $userId && $r['property_id'] === $propertyId);
    }

    public function findByUser(int $userId): array
    {
        $favorites = array_map(
            Favorite::fromArray(...),
            $this->db->select(self::TABLE, fn (array $r): bool => $r['user_id'] === $userId)
        );
        usort($favorites, fn (Favorite $a, Favorite $b): int => $b->addedAt <=> $a->addedAt);

        return $favorites;
    }
}
