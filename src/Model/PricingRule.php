<?php

declare(strict_types=1);

namespace App\Model;

use App\Support\Row;

/** Vergi / ek ücret kuralı. $value x100: yüzde ise baz puan, sabit ise kuruş. */
final class PricingRule
{
    public function __construct(
        public ?int $id,
        public int $propertyId,
        public string $name,
        public PricingRuleType $type,
        public CalcMethod $calcMethod,
        public int $value,
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromArray(array $r): self
    {
        return new self(
            Row::int($r, 'id'),
            Row::int($r, 'property_id'),
            Row::string($r, 'name'),
            PricingRuleType::from(Row::string($r, 'type')),
            CalcMethod::from(Row::string($r, 'calc_method')),
            Row::decimal($r, 'value'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->propertyId,
            'name' => $this->name,
            'type' => $this->type->value,
            'calc_method' => $this->calcMethod->value,
            'value' => Row::fmtDecimal($this->value),
        ];
    }
}
