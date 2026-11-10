<?php

declare(strict_types=1);

namespace App\Repository\Json;

use App\Model\PricingRule;
use App\Repository\PricingRuleRepositoryInterface;

final class JsonPricingRuleRepository implements PricingRuleRepositoryInterface
{
    private const TABLE = 'pricing_rules';

    public function __construct(private readonly JsonDatabase $db)
    {
    }

    public function findById(int $id): ?PricingRule
    {
        $row = $this->db->first(self::TABLE, fn (array $r): bool => $r['id'] === $id);

        return $row === null ? null : PricingRule::fromArray($row);
    }

    public function findByProperty(int $propertyId): array
    {
        return array_map(
            PricingRule::fromArray(...),
            $this->db->select(self::TABLE, fn (array $r): bool => $r['property_id'] === $propertyId)
        );
    }

    public function save(PricingRule $rule): PricingRule
    {
        if ($rule->id === null) {
            return PricingRule::fromArray($this->db->insert(self::TABLE, $rule->toArray()));
        }
        $id = $rule->id;
        $this->db->update(self::TABLE, fn (array $r): bool => $r['id'] === $id, $rule->toArray());

        return $rule;
    }

    public function delete(int $id): void
    {
        $this->db->delete(self::TABLE, fn (array $r): bool => $r['id'] === $id);
    }
}
