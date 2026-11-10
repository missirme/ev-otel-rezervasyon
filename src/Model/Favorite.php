<?php

declare(strict_types=1);

namespace App\Model;

use App\Support\Row;
use DateTimeImmutable;

/** Favori (FR-19). Birleşik anahtar (user_id, property_id): aynı ilan iki kez eklenemez. */
final class Favorite
{
    public function __construct(public int $userId, public int $propertyId, public DateTimeImmutable $addedAt)
    {
    }

    /** @param array<string, mixed> $r */
    public static function fromArray(array $r): self
    {
        return new self(Row::int($r, 'user_id'), Row::int($r, 'property_id'), Row::dateTime($r, 'added_at'));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'property_id' => $this->propertyId,
            'added_at' => Row::fmtDateTime($this->addedAt),
        ];
    }
}
