<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Review;

interface ReviewRepositoryInterface
{
    public function findById(int $id): ?Review;

    public function findByReservation(int $reservationId): ?Review;

    /**
     * İlanın yorumları (reviews ⋈ reservations ⋈ bookable_units birleştirmesi).
     *
     * @return list<Review>
     */
    public function findForProperty(int $propertyId, bool $publishedOnly = true): array;

    /**
     * Yayındaki yorumlardan ilan başına ortalama puan ve yorum sayısı.
     *
     * @return array<int, array{avg: float, count: int}>
     */
    public function ratingSummaries(): array;

    /** @return list<Review> */
    public function all(): array;

    public function save(Review $review): Review;
}
