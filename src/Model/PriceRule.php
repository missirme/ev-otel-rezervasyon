<?php

declare(strict_types=1);

namespace App\Model;

use App\Support\Row;
use DateTimeImmutable;

/**
 * Fiyat kuralı (FR-43, SRS 4.10): sezon farkı, hafta sonu farkı veya uzun konaklama indirimi.
 * $value DECIMAL(10,2) değerinin 100 katıdır: yüzde ise baz puan (3000 = %30), sabit ise kuruş.
 */
final class PriceRule
{
    public function __construct(
        public ?int $id,
        public int $propertyId,
        public ?int $unitId,
        public PriceRuleType $type,
        public string $name,
        public ?DateTimeImmutable $startDate,
        public ?DateTimeImmutable $endDate,
        public ?int $minNights,
        public int $value,
        public ValueType $valueType,
        public int $priority,
    ) {
    }

    public function appliesToUnit(int $unitId): bool
    {
        return $this->unitId === null || $this->unitId === $unitId;
    }

    /** Sezon kuralı geceyi kapsıyor mu? Başlangıç ve bitiş geceleri dahildir. */
    public function coversNight(DateTimeImmutable $night): bool
    {
        return $this->startDate !== null && $this->endDate !== null
            && $night >= $this->startDate && $night <= $this->endDate;
    }

    /** @param array<string, mixed> $r */
    public static function fromArray(array $r): self
    {
        return new self(
            Row::int($r, 'id'),
            Row::int($r, 'property_id'),
            Row::nullableInt($r, 'unit_id'),
            PriceRuleType::from(Row::string($r, 'type')),
            Row::string($r, 'name'),
            Row::nullableDate($r, 'start_date'),
            Row::nullableDate($r, 'end_date'),
            Row::nullableInt($r, 'min_nights'),
            Row::decimal($r, 'value'),
            ValueType::from(Row::string($r, 'value_type')),
            Row::int($r, 'priority'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->propertyId,
            'unit_id' => $this->unitId,
            'type' => $this->type->value,
            'name' => $this->name,
            'start_date' => Row::fmtDate($this->startDate),
            'end_date' => Row::fmtDate($this->endDate),
            'min_nights' => $this->minNights,
            'value' => Row::fmtDecimal($this->value),
            'value_type' => $this->valueType->value,
            'priority' => $this->priority,
        ];
    }
}
