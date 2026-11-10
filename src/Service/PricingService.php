<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ValidationException;
use App\Model\BookableUnit;
use App\Model\CalcMethod;
use App\Model\ChargeType;
use App\Model\PricingRuleType;
use App\Repository\CalendarRepositoryInterface;
use App\Repository\PriceRuleRepositoryInterface;
use App\Repository\PricingRuleRepositoryInterface;
use App\Service\Pricing\BaseRateStrategy;
use App\Service\Pricing\LongStayDiscountStrategy;
use App\Service\Pricing\PriceLine;
use App\Service\Pricing\PriceQuote;
use App\Service\Pricing\PricingContext;
use App\Service\Pricing\PricingStrategyInterface;
use App\Service\Pricing\SeasonalStrategy;
use App\Service\Pricing\WeekendStrategy;
use App\Support\DateRange;
use App\Support\Money;

/**
 * Sezonluk dinamik fiyat (ÇP-2, SRS 4.10). Yan etkisizdir: aynı girdi aynı sonucu verir.
 *
 * Hesap sırası (BR-23 - BR-26):
 *  1. Gece temel fiyatı (takvim istisnası varsa o fiyat)        -> BaseRateStrategy
 *  2. Sezon farkı (en yüksek öncelikli kural)                    -> SeasonalStrategy
 *  3. Hafta sonu farkı (Cuma, Cumartesi)                         -> WeekendStrategy
 *  4. Her gece kuruşa yarıdan yukarı yuvarlanır (tek seferde)
 *  5. Uzun konaklama indirimi (ayrı eksi kalem)                  -> LongStayDiscountStrategy
 *  6. Vergi ve ek ücretler indirimli tutar üzerinden eklenir
 */
final class PricingService
{
    /** @var list<PricingStrategyInterface> gece bazında çalışan stratejiler */
    private array $nightlyStrategies;

    /** @var list<PricingStrategyInterface> konaklama toplamına uygulanan stratejiler */
    private array $stayStrategies;

    public function __construct(
        private readonly PriceRuleRepositoryInterface $priceRules,
        private readonly PricingRuleRepositoryInterface $pricingRules,
        private readonly CalendarRepositoryInterface $calendar,
    ) {
        $this->nightlyStrategies = [new BaseRateStrategy(), new SeasonalStrategy(), new WeekendStrategy()];
        $this->stayStrategies = [new LongStayDiscountStrategy()];
    }

    public function quote(BookableUnit $unit, DateRange $range): PriceQuote
    {
        if ($range->nights() < 1) {
            throw ValidationException::single('cikis', 'Çıkış tarihi giriş tarihinden sonra olmalıdır.');
        }
        $unitId = (int) $unit->id;
        $context = new PricingContext(
            $unit,
            $range,
            $this->priceRules->findByProperty($unit->propertyId),
            $this->calendar->findForUnit($unitId, $range->checkIn, $range->checkOut),
        );

        foreach ($this->nightlyStrategies as $strategy) {
            $strategy->apply($context);
        }
        // BR-25: yuvarlama gece bazında ve tek seferde yapılır; döküm toplamı konaklama tutarına eşit olur.
        foreach ($context->nights as $night) {
            $night->price = Money::applyFactors($night->base, $night->factors);
        }
        foreach ($this->stayStrategies as $strategy) {
            $strategy->apply($context);
        }

        $stayTotal = $context->stayTotal();
        $discounted = $stayTotal + ($context->discount->amount ?? 0);
        $extras = [];
        foreach ($this->pricingRules->findByProperty($unit->propertyId) as $rule) {
            $nights = $range->nights();
            [$amount, $label] = match ($rule->calcMethod) {
                CalcMethod::Yuzde => [Money::percent($discounted, $rule->value), $rule->name . ' (' . Money::formatPercent($rule->value) . ')'],
                CalcMethod::GecelikSabit => [$rule->value * $nights, $rule->name . ' (' . $nights . ' gece × ' . Money::format($rule->value) . ')'],
                CalcMethod::RezervasyonBasinaSabit => [$rule->value, $rule->name],
            };
            $type = $rule->type === PricingRuleType::Vergi ? ChargeType::Vergi : ChargeType::EkUcret;
            $extras[] = new PriceLine($type, $label, $amount);
        }

        $total = $discounted + array_sum(array_map(fn (PriceLine $l): int => $l->amount, $extras));

        return new PriceQuote($unitId, $range, $context->nights, $stayTotal, $context->discount, $extras, $total);
    }
}
