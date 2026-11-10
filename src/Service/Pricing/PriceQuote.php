<?php

declare(strict_types=1);

namespace App\Service\Pricing;

use App\Model\ChargeType;
use App\Model\ReservationCharge;
use App\Support\DateRange;
use App\Support\Money;

/**
 * Fiyat dökümü (FR-14, FR-44). Arama, ilan detayı ve rezervasyon aynı hesabı kullanır.
 * Toplam = konaklama toplamı - indirim + vergiler + ek ücretler.
 */
final class PriceQuote
{
    /**
     * @param list<NightPrice> $nights
     * @param list<PriceLine> $extras vergi ve ek ücretler
     */
    public function __construct(
        public readonly int $unitId,
        public readonly DateRange $range,
        public readonly array $nights,
        public readonly int $stayTotal,
        public readonly ?PriceLine $discount,
        public readonly array $extras,
        public readonly int $total,
    ) {
    }

    public function nightCount(): int
    {
        return count($this->nights);
    }

    /** Arama kartında gösterilen ortalama gecelik fiyat. */
    public function averageNightly(): int
    {
        return $this->nightCount() === 0 ? 0 : Money::roundDiv($this->stayTotal, $this->nightCount());
    }

    /** Kalemlerin toplamı; her zaman $total'e eşittir (T-13). */
    public function linesTotal(): int
    {
        $sum = 0;
        foreach ($this->toCharges() as $charge) {
            $sum += $charge->amount;
        }

        return $sum;
    }

    /**
     * Rezervasyonla saklanacak kalemler (fiyat anlık görüntüsü, BR-16).
     *
     * @return list<ReservationCharge>
     */
    public function toCharges(): array
    {
        $charges = [];
        foreach ($this->nights as $night) {
            $charges[] = new ReservationCharge(null, 0, ChargeType::KonaklamaGecesi, $night->date, $night->description(), $night->price);
        }
        if ($this->discount !== null) {
            $charges[] = new ReservationCharge(null, 0, $this->discount->type, null, $this->discount->description, $this->discount->amount);
        }
        foreach ($this->extras as $line) {
            $charges[] = new ReservationCharge(null, 0, $line->type, null, $line->description, $line->amount);
        }

        return $charges;
    }
}
