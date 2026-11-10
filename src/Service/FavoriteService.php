<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\NotFoundException;
use App\Model\Favorite;
use App\Model\PropertyStatus;
use App\Repository\FavoriteRepositoryInterface;
use App\Repository\PropertyRepositoryInterface;
use App\Repository\TransactionManagerInterface;
use App\Support\ClockInterface;

/** Favoriler (FR-19). Aynı ilan aynı kullanıcının favorilerine iki kez eklenemez. */
final class FavoriteService
{
    public function __construct(
        private readonly FavoriteRepositoryInterface $favorites,
        private readonly PropertyRepositoryInterface $properties,
        private readonly TransactionManagerInterface $tx,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Favoriye ekler ya da çıkarır; yeni durumu döndürür (true = favoride). */
    public function toggle(int $userId, int $propertyId): bool
    {
        return $this->tx->transactional(function () use ($userId, $propertyId): bool {
            $property = $this->properties->findById($propertyId);
            if ($property === null || $property->status === PropertyStatus::Kaldirildi) {
                throw new NotFoundException('İlan bulunamadı.');
            }
            if ($this->favorites->exists($userId, $propertyId)) {
                $this->favorites->remove($userId, $propertyId);

                return false;
            }
            $this->favorites->add(new Favorite($userId, $propertyId, $this->clock->now()));

            return true;
        });
    }

    /** @return list<int> */
    public function propertyIds(int $userId): array
    {
        return array_map(fn (Favorite $f): int => $f->propertyId, $this->favorites->findByUser($userId));
    }
}
