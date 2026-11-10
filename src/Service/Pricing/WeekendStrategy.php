<?php

declare(strict_types=1);

namespace App\Service\Pricing;

use App\Model\PriceRule;
use App\Model\PriceRuleType;
use App\Support\Money;

/**
 * Hesap sırası 3: hafta sonu farkı Cuma ve Cumartesi gecelerine uygulanır (BR-23).
 * Gece, konaklamanın başladığı güne göre sayılır. Birime özel kural ilan geneline göre önceliklidir.
 */
final class WeekendStrategy implements PricingStrategyInterface
{
    public function apply(PricingContext $context): void
    {
        $rule = $this->pickRule($context->rulesOfType(PriceRuleType::HaftaSonu));
        if ($rule === null) {
            return;
        }
        foreach ($context->nights as $night) {
            if ($night->isException || !$night->isWeekend()) {
                continue;
            }
            $night->factors[] = $rule->value;
            $night->labels[] = 'hafta sonu +' . Money::formatPercent($rule->value);
        }
    }

    /** @param list<PriceRule> $rules */
    private function pickRule(array $rules): ?PriceRule
    {
        usort($rules, fn (PriceRule $a, PriceRule $b): int => [$b->unitId !== null, $b->priority, -(int) $b->id]
            <=> [$a->unitId !== null, $a->priority, -(int) $a->id]);

        return $rules[0] ?? null;
    }
}
