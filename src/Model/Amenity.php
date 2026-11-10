<?php

declare(strict_types=1);

namespace App\Model;

use App\Support\Row;

/** Olanak (Wi-Fi, kahvaltı...). İlan-olanak ilişkisi property_amenities ara tablosundadır. */
final class Amenity
{
    public function __construct(public ?int $id, public string $name)
    {
    }

    /** @param array<string, mixed> $r */
    public static function fromArray(array $r): self
    {
        return new self(Row::int($r, 'id'), Row::string($r, 'name'));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}
