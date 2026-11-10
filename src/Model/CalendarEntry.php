<?php

declare(strict_types=1);

namespace App\Model;

use App\Support\Row;
use DateTimeImmutable;

/**
 * Takvim istisnası: ilan sahibinin belirli bir gece için girdiği kapalı durum veya özel fiyat.
 * Rezervasyon doluluğu burada tutulmaz; tek doğruluk kaynağı rezervasyon geceleridir (SRS 7.1).
 */
final class CalendarEntry
{
    public function __construct(
        public ?int $id,
        public int $unitId,
        public DateTimeImmutable $date,
        public CalendarStatus $status,
        public ?int $specialPrice, // kuruş, boş olabilir
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromArray(array $r): self
    {
        return new self(
            Row::int($r, 'id'),
            Row::int($r, 'unit_id'),
            Row::date($r, 'date'),
            CalendarStatus::from(Row::string($r, 'status')),
            Row::nullableDecimal($r, 'special_price'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'unit_id' => $this->unitId,
            'date' => Row::fmtDate($this->date),
            'status' => $this->status->value,
            'special_price' => Row::fmtDecimal($this->specialPrice),
        ];
    }
}
