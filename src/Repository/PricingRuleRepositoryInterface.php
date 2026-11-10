<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\PricingRule;

/** Vergi / ek ücret kuralları. */
interface PricingRuleRepositoryInterface
{
    public function findById(int $id): ?PricingRule;

    /** @return list<PricingRule> */
    public function findByProperty(int $propertyId): array;

    public function save(PricingRule $rule): PricingRule;

    public function delete(int $id): void;
}
