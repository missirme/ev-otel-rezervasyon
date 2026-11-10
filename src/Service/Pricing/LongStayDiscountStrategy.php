<?php

declare(strict_types=1);

namespace App\Service\Pricing;

use App\Model\ChargeType;
use App\Model\PriceRule;
use App\Model\PriceRuleType;
use App\Model\ValueType;
use App\Support\Money;

/**
 * Hesap sırası 4 (BR-24): uzun konaklama indirimi konaklama toplamına uygulanır ve
 * ayrı bir eksi kalem olarak gösterilir. Birden fazla eşik varsa ulaşılan en yüksek eşik geçerlidir.
 */
final class LongStayDiscountStrategy implements PricingStrategyInterface
{
    public function apply(PricingContext $context): void
    {
        $nights = count($context->nights);
        $best = null;
        foreach ($context->rulesOfType(PriceRuleType::UzunKonaklama) as $rule) {
            $min = (int) $rule->minNights;
            if ($min < 1 || $nights < $min) {
                continue;
            }
            if ($best === null || $min > (int) $best->minNights || ($min === (int) $best->minNights && $rule->value > $best->value)) {
                $best = $rule;
            }
        }
        if (!$best instanceof PriceRule) {
            return;
        }
        $stay = $context->stayTotal();
        if ($best->valueType === ValueType::Yuzde) {
            $amount = Money::percent($stay, $best->value);
            $label = sprintf('Uzun konaklama indirimi (%d+ gece, %s)', (int) $best->minNights, Money::formatPercent($best->value));
        } else {
            $amount = min($best->value, $stay);
            $label = sprintf('Uzun konaklama indirimi (%d+ gece)', (int) $best->minNights);
        }
        if ($amount > 0) {
            $context->discount = new PriceLine(ChargeType::Indirim, $label, -$amount);
        }
    }
}
