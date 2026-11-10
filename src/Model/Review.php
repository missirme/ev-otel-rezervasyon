<?php

declare(strict_types=1);

namespace App\Model;

use App\Support\Row;
use DateTimeImmutable;

/** Yorum: yalnızca Tamamlandı rezervasyona, rezervasyon başına en çok bir tane (BR-09, BR-21). */
final class Review
{
    public function __construct(
        public ?int $id,
        public int $reservationId,
        public int $customerId,
        public int $rating,
        public string $comment,
        public ModerationStatus $moderationStatus,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromArray(array $r): self
    {
        return new self(
            Row::int($r, 'id'),
            Row::int($r, 'reservation_id'),
            Row::int($r, 'customer_id'),
            Row::int($r, 'rating'),
            Row::string($r, 'comment'),
            ModerationStatus::from(Row::string($r, 'moderation_status')),
            Row::dateTime($r, 'created_at'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservationId,
            'customer_id' => $this->customerId,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'moderation_status' => $this->moderationStatus->value,
            'created_at' => Row::fmtDateTime($this->createdAt),
        ];
    }
}
