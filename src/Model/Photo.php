<?php

declare(strict_types=1);

namespace App\Model;

use App\Support\Row;

/** İlan fotoğrafı (FR-46). Dosya adı sunucuda rastgele üretilir (NFR-19). */
final class Photo
{
    public function __construct(
        public ?int $id,
        public int $propertyId,
        public ?int $unitId,
        public string $fileName,
        public string $mimeType,
        public int $sizeBytes,
        public int $sortOrder,
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromArray(array $r): self
    {
        return new self(
            Row::int($r, 'id'),
            Row::int($r, 'property_id'),
            Row::nullableInt($r, 'unit_id'),
            Row::string($r, 'file_name'),
            Row::string($r, 'mime_type'),
            Row::int($r, 'size_bytes'),
            Row::int($r, 'sort_order'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->propertyId,
            'unit_id' => $this->unitId,
            'file_name' => $this->fileName,
            'mime_type' => $this->mimeType,
            'size_bytes' => $this->sizeBytes,
            'sort_order' => $this->sortOrder,
        ];
    }
}
