<?php

declare(strict_types=1);

namespace App\Model;

use App\Support\Row;
use DateTimeImmutable;

/** Şikâyet (FR-27): ilan veya rezervasyon hakkında. */
final class Complaint
{
    public function __construct(
        public ?int $id,
        public int $senderId,
        public ?int $propertyId,
        public ?int $reservationId,
        public string $description,
        public ComplaintStatus $status,
        public ?string $adminNote,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromArray(array $r): self
    {
        return new self(
            Row::int($r, 'id'),
            Row::int($r, 'sender_id'),
            Row::nullableInt($r, 'property_id'),
            Row::nullableInt($r, 'reservation_id'),
            Row::string($r, 'description'),
            ComplaintStatus::from(Row::string($r, 'status')),
            Row::nullableString($r, 'admin_note'),
            Row::dateTime($r, 'created_at'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sender_id' => $this->senderId,
            'property_id' => $this->propertyId,
            'reservation_id' => $this->reservationId,
            'description' => $this->description,
            'status' => $this->status->value,
            'admin_note' => $this->adminNote,
            'created_at' => Row::fmtDateTime($this->createdAt),
        ];
    }
}
