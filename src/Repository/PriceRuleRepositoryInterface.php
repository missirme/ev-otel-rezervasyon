<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\PriceRule;

interface PriceRuleRepositoryInterface
{
    public function findById(int $id): ?PriceRule;

    /** @return list<PriceRule> */
    public function findByProperty(int $propertyId): array;

    public function save(PriceRule $rule): PriceRule;

    public function delete(int $id): void;
}
