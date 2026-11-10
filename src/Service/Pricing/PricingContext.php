<?php

declare(strict_types=1);

namespace App\Service\Pricing;

use App\Model\BookableUnit;
use App\Model\CalendarEntry;
use App\Model\PriceRule;
use App\Model\PriceRuleType;
use App\Support\DateRange;

/** Stratejilerin üzerinde çalıştığı hesap bağlamı (birim, aralık, kurallar, takvim istisnaları). */
final class PricingContext
{
    /** @var list<NightPrice> */
    public array $nights = [];

    public ?PriceLine $discount = null;

    /**
     * @param list<PriceRule> $rules ilanın fiyat kuralları
     * @param array<string, CalendarEntry> $exceptions "Y-m-d" => takvim istisnası
     */
    public function __construct(
        public readonly BookableUnit $unit,
        public readonly DateRange $range,
        public readonly array $rules,
        public readonly array $exceptions,
    ) {
        foreach ($range->nightDates() as $date) {
            $this->nights[] = new NightPrice($date);
        }
    }

    /**
     * Bu birime uygulanan, verilen türdeki kurallar.
     *
     * @return list<PriceRule>
     */
    public function rulesOfType(PriceRuleType $type): array
    {
        $unitId = (int) $this->unit->id;

        return array_values(array_filter(
            $this->rules,
            fn (PriceRule $r): bool => $r->type === $type && $r->appliesToUnit($unitId)
        ));
    }

    /** Konaklama toplamı = gece fiyatlarının toplamı. */
    public function stayTotal(): int
    {
        return array_sum(array_map(fn (NightPrice $n): int => $n->price, $this->nights));
    }
}
