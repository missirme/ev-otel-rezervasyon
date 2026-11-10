<?php

declare(strict_types=1);

namespace App\Model;

use App\Support\Row;

/** Konaklama ilanı: bir otel veya bir kiralık ev (SRS 7). */
final class Property
{
    public function __construct(
        public ?int $id,
        public int $ownerId,
        public string $name,
        public PropertyType $type,
        public string $description,
        public string $address,
        public string $city,
        public BookingMode $bookingMode,
        public CancellationPolicy $cancellationPolicy,
        public PropertyStatus $status,
    ) {
    }

    public function isBookable(): bool
    {
        return $this->status === PropertyStatus::Aktif;
    }

    /** @param array<string, mixed> $r */
    public static function fromArray(array $r): self
    {
        return new self(
            Row::int($r, 'id'),
            Row::int($r, 'owner_id'),
            Row::string($r, 'name'),
            PropertyType::from(Row::string($r, 'type')),
            Row::string($r, 'description'),
            Row::string($r, 'address'),
            Row::string($r, 'city'),
            BookingMode::from(Row::string($r, 'booking_mode')),
            CancellationPolicy::from(Row::string($r, 'cancellation_policy')),
            PropertyStatus::from(Row::string($r, 'status')),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'owner_id' => $this->ownerId,
            'name' => $this->name,
            'type' => $this->type->value,
            'description' => $this->description,
            'address' => $this->address,
            'city' => $this->city,
            'booking_mode' => $this->bookingMode->value,
            'cancellation_policy' => $this->cancellationPolicy->value,
            'status' => $this->status->value,
        ];
    }
}
