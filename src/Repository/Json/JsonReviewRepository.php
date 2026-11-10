<?php

declare(strict_types=1);

namespace App\Repository\Json;

use App\Model\ModerationStatus;
use App\Model\Review;
use App\Repository\ReviewRepositoryInterface;

final class JsonReviewRepository implements ReviewRepositoryInterface
{
    private const TABLE = 'reviews';

    public function __construct(private readonly JsonDatabase $db)
    {
    }

    public function findById(int $id): ?Review
    {
        $row = $this->db->first(self::TABLE, fn (array $r): bool => $r['id'] === $id);

        return $row === null ? null : Review::fromArray($row);
    }

    public function findByReservation(int $reservationId): ?Review
    {
        $row = $this->db->first(self::TABLE, fn (array $r): bool => $r['reservation_id'] === $reservationId);

        return $row === null ? null : Review::fromArray($row);
    }

    public function findForProperty(int $propertyId, bool $publishedOnly = true): array
    {
        $propertyOfReservation = $this->propertyOfReservation();
        $reviews = [];
        foreach ($this->db->select(self::TABLE) as $row) {
            if (($propertyOfReservation[$row['reservation_id']] ?? null) !== $propertyId) {
                continue;
            }
            if ($publishedOnly && $row['moderation_status'] !== ModerationStatus::Yayinda->value) {
                continue;
            }
            $reviews[] = Review::fromArray($row);
        }
        usort($reviews, fn (Review $a, Review $b): int => $b->createdAt <=> $a->createdAt);

        return $reviews;
    }

    public function ratingSummaries(): array
    {
        $propertyOfReservation = $this->propertyOfReservation();
        $sum = [];
        $count = [];
        foreach ($this->db->select(self::TABLE, fn (array $r): bool => $r['moderation_status'] === ModerationStatus::Yayinda->value) as $row) {
            $propertyId = $propertyOfReservation[$row['reservation_id']] ?? null;
            if ($propertyId === null) {
                continue;
            }
            $sum[$propertyId] = ($sum[$propertyId] ?? 0) + (int) $row['rating'];
            $count[$propertyId] = ($count[$propertyId] ?? 0) + 1;
        }
        $result = [];
        foreach ($sum as $propertyId => $total) {
            $result[$propertyId] = ['avg' => round($total / $count[$propertyId], 1), 'count' => $count[$propertyId]];
        }

        return $result;
    }

    public function all(): array
    {
        $reviews = array_map(Review::fromArray(...), $this->db->select(self::TABLE));
        usort($reviews, fn (Review $a, Review $b): int => $b->createdAt <=> $a->createdAt);

        return $reviews;
    }

    public function save(Review $review): Review
    {
        if ($review->id === null) {
            return Review::fromArray($this->db->insert(self::TABLE, $review->toArray()));
        }
        $id = $review->id;
        $this->db->update(self::TABLE, fn (array $r): bool => $r['id'] === $id, $review->toArray());

        return $review;
    }

    /**
     * reservations ⋈ bookable_units: rezervasyon id -> ilan id (Aşama 3'te JOIN'li SQL sorgusu).
     *
     * @return array<int, int>
     */
    private function propertyOfReservation(): array
    {
        $propertyOfUnit = [];
        foreach ($this->db->select('bookable_units') as $unit) {
            $propertyOfUnit[(int) $unit['id']] = (int) $unit['property_id'];
        }
        $map = [];
        foreach ($this->db->select('reservations') as $reservation) {
            $map[(int) $reservation['id']] = $propertyOfUnit[(int) $reservation['unit_id']] ?? 0;
        }

        return $map;
    }
}
