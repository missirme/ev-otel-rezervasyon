<?php

declare(strict_types=1);

namespace App\Repository\Json;

use App\Model\PriceRule;
use App\Repository\PriceRuleRepositoryInterface;

final class JsonPriceRuleRepository implements PriceRuleRepositoryInterface
{
    private const TABLE = 'price_rules';

    public function __construct(private readonly JsonDatabase $db)
    {
    }

    public function findById(int $id): ?PriceRule
    {
        $row = $this->db->first(self::TABLE, fn (array $r): bool => $r['id'] === $id);

        return $row === null ? null : PriceRule::fromArray($row);
    }

    public function findByProperty(int $propertyId): array
    {
        return array_map(
            PriceRule::fromArray(...),
            $this->db->select(self::TABLE, fn (array $r): bool => $r['property_id'] === $propertyId)
        );
    }

    public function save(PriceRule $rule): PriceRule
    {
        if ($rule->id === null) {
            return PriceRule::fromArray($this->db->insert(self::TABLE, $rule->toArray()));
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
