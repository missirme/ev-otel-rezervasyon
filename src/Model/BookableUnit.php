<?php

declare(strict_types=1);

namespace App\Model;

use App\Support\Row;

/**
 * Rezervasyonun yapıldığı tekil kaynak: otelde her oda, kiralık evde evin kendisi.
 * Çakışma yalnızca aynı birim içinde aranır (BR-04).
 */
final class BookableUnit
{
    public function __construct(
        public ?int $id,
        public int $propertyId,
        public string $name,
        public RoomType $roomType,
        public int $capacity,
        public int $basePrice, // kuruş
        public UnitStatus $status,
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromArray(array $r): self
    {
        return new self(
            Row::int($r, 'id'),
            Row::int($r, 'property_id'),
            Row::string($r, 'name'),
            RoomType::from(Row::string($r, 'room_type')),
            Row::int($r, 'capacity'),
            Row::decimal($r, 'base_price'),
            UnitStatus::from(Row::string($r, 'status')),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->propertyId,
            'name' => $this->name,
            'room_type' => $this->roomType->value,
            'capacity' => $this->capacity,
            'base_price' => Row::fmtDecimal($this->basePrice),
            'status' => $this->status->value,
        ];
    }
}
