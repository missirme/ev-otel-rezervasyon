<?php

declare(strict_types=1);

namespace App\Service\Pricing;

use App\Model\PriceRule;
use App\Model\PriceRuleType;
use App\Support\Money;

/**
 * Hesap sırası 2: sezon farkı. Aynı geceyi kapsayan birden fazla sezon kuralı varsa
 * yalnızca en yüksek öncelikli olan uygulanır (BR-26). Başlangıç ve bitiş geceleri dahildir.
 */
final class SeasonalStrategy implements PricingStrategyInterface
{
    public function apply(PricingContext $context): void
    {
        $rules = $context->rulesOfType(PriceRuleType::Sezon);
        if ($rules === []) {
            return;
        }
        foreach ($context->nights as $night) {
            if ($night->isException) {
                continue;
            }
            $best = null;
            foreach ($rules as $rule) {
                if (!$rule->coversNight($night->date)) {
                    continue;
                }
                if ($best === null || $rule->priority > $best->priority || ($rule->priority === $best->priority && (int) $rule->id < (int) $best->id)) {
                    $best = $rule;
                }
            }
            if ($best instanceof PriceRule) {
                $night->factors[] = $best->value;
                $night->labels[] = $best->name . ' ' . self::signed($best->value);
            }
        }
    }

    private static function signed(int $basisPoints): string
    {
        return ($basisPoints >= 0 ? '+' : '') . Money::formatPercent($basisPoints);
    }
}
