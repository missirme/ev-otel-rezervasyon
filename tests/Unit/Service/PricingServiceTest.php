<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Model\CalendarEntry;
use App\Model\CalendarStatus;
use App\Model\ChargeType;
use App\Model\Role;
use Tests\Support\AppTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * T-12 / T-13 (SRS 4.10): gece bazlı fiyat matrisi, öncelik, sınır geceleri, uzun konaklama
 * eşikleri, takvim istisnası, yuvarlama ve döküm tutarlılığı. 9 Temmuz 2027 Cuma günüdür.
 */
#[Group('FR-14')]
#[Group('FR-44')]
final class PricingServiceTest extends AppTestCase
{
    private int $propertyId;
    private \App\Model\BookableUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $host = $this->user(Role::IlanSahibi);
        $this->propertyId = (int) $this->property((int) $host->id)->id;
        $this->unit = $this->unit($this->propertyId, 1000);
    }

    private function fullRules(): void
    {
        $this->seasonRule($this->propertyId, '2027-07-01', '2027-07-31', 30, 1, 'Temmuz');
        $this->weekendRule($this->propertyId, 20);
        $this->longStayRule($this->propertyId, 7, 10);
        $this->taxRule($this->propertyId, 10);
    }

    #[Group('AC-23')]
    #[Group('T-12')]
    #[Group('BR-23')]
    public function testAc23SeasonAndWeekendNightlyBreakdown(): void
    {
        $this->fullRules();
        $quote = $this->c->pricingService()->quote($this->unit, $this->range('2027-07-09', '2027-07-12'));

        self::assertSame([156000, 156000, 130000], array_map(fn ($n) => $n->price, $quote->nights)); // Cum, Cmt, Paz
        self::assertSame(442000, $quote->stayTotal);
        self::assertNull($quote->discount);
        self::assertSame(44200, $quote->extras[0]->amount);
        self::assertSame(486200, $quote->total);
    }

    #[Group('AC-24')]
    #[Group('T-12')]
    #[Group('BR-24')]
    public function testAc24LongStayDiscountAndTax(): void
    {
        $this->fullRules();
        $quote = $this->c->pricingService()->quote($this->unit, $this->range('2027-07-12', '2027-07-19'));

        self::assertSame(962000, $quote->stayTotal);           // 5 x 1.300 + 2 x 1.560
        self::assertSame(-96200, $quote->discount?->amount);    // %10 ayrı eksi kalem
        self::assertSame(86580, $quote->extras[0]->amount);     // vergi indirimli tutar üzerinden
        self::assertSame(952380, $quote->total);                // 9.523,80 TL
    }

    #[Group('T-12')]
    #[Group('BR-23')]
    #[Group('FR-43')]
    public function testBaseOnlyWeekendOnlyAndSeasonOnly(): void
    {
        $pricing = $this->c->pricingService();
        self::assertSame(200000, $pricing->quote($this->unit, $this->range('2026-11-09', '2026-11-11'))->total, 'yalnızca temel fiyat');

        $this->weekendRule($this->propertyId, 20);
        // 13 Kasım 2026 Cuma, 14 Cumartesi, 15 Pazar
        $weekend = $pricing->quote($this->unit, $this->range('2026-11-13', '2026-11-16'));
        self::assertSame([120000, 120000, 100000], array_map(fn ($n) => $n->price, $weekend->nights), 'yalnızca hafta sonu');

        $this->seasonRule($this->propertyId, '2026-12-01', '2026-12-31', 30);
        // 1 Aralık 2026 Salı: sezon var, hafta sonu yok
        self::assertSame(130000, $pricing->quote($this->unit, $this->range('2026-12-01', '2026-12-02'))->total, 'yalnızca sezon');
    }

    #[Group('T-12')]
    #[Group('BR-23')]
    public function testSeasonBoundaryNightsAreInclusive(): void
    {
        $this->seasonRule($this->propertyId, '2027-07-01', '2027-07-31', 30);
        $this->weekendRule($this->propertyId, 20);
        // 30 Temmuz Cuma (sezon+hs), 31 Temmuz Cumartesi (sezonun son gecesi + hs), 1 Ağustos Pazar (sezon dışı)
        $quote = $this->c->pricingService()->quote($this->unit, $this->range('2027-07-30', '2027-08-02'));

        self::assertSame([156000, 156000, 100000], array_map(fn ($n) => $n->price, $quote->nights));
        // 30 Haziran gecesi sezon öncesidir
        self::assertSame(100000, $this->c->pricingService()->quote($this->unit, $this->range('2027-06-30', '2027-07-01'))->total);
    }

    #[Group('T-12')]
    #[Group('AC-25')]
    #[Group('BR-23')]
    public function testCalendarExceptionOverridesSeasonAndWeekend(): void
    {
        $this->seasonRule($this->propertyId, '2027-07-01', '2027-07-31', 30);
        $this->weekendRule($this->propertyId, 20);
        $this->c->calendar()->upsert(new CalendarEntry(null, (int) $this->unit->id, $this->d('2027-07-10'), CalendarStatus::Acik, 200000));

        $quote = $this->c->pricingService()->quote($this->unit, $this->range('2027-07-09', '2027-07-12'));

        self::assertSame([156000, 200000, 130000], array_map(fn ($n) => $n->price, $quote->nights));
        self::assertTrue($quote->nights[1]->isException);
    }

    #[Group('T-12')]
    #[Group('AC-25')]
    #[Group('BR-26')]
    public function testHigherPrioritySeasonWins(): void
    {
        $this->seasonRule($this->propertyId, '2027-07-01', '2027-07-31', 30, 1, 'Yaz');
        $this->seasonRule($this->propertyId, '2027-07-12', '2027-07-12', 50, 2, 'Festival');

        // 12 Temmuz 2027 Pazartesi: iki sezon da kapsıyor, öncelik 2 (Festival %50) uygulanır
        self::assertSame(150000, $this->c->pricingService()->quote($this->unit, $this->range('2027-07-12', '2027-07-13'))->total);
        self::assertSame(130000, $this->c->pricingService()->quote($this->unit, $this->range('2027-07-13', '2027-07-14'))->total);
    }

    #[Group('T-12')]
    #[Group('BR-24')]
    public function testLongStayThresholds(): void
    {
        $this->longStayRule($this->propertyId, 7, 10);
        $this->longStayRule($this->propertyId, 28, 20);
        $pricing = $this->c->pricingService();

        self::assertNull($pricing->quote($this->unit, $this->range('2026-12-01', '2026-12-07'))->discount, '6 gece: indirim yok');
        self::assertSame(-70000, $pricing->quote($this->unit, $this->range('2026-12-01', '2026-12-08'))->discount?->amount, '7 gece: %10');
        self::assertSame(-560000, $pricing->quote($this->unit, $this->range('2026-12-01', '2026-12-29'))->discount?->amount, '28 gece: en yüksek eşik %20');
    }

    #[Group('T-13')]
    #[Group('BR-25')]
    #[Group('AC-21')]
    public function testRoundingIsPerNightAndLinesSumToTotal(): void
    {
        $unit = $this->unit($this->propertyId, 1, 2, '333');
        $unit->basePrice = 33333; // 333,33 TL
        $unit = $this->c->units()->save($unit);
        $this->seasonRule($this->propertyId, '2027-07-01', '2027-07-31', 30);
        $this->weekendRule($this->propertyId, 20);
        $this->longStayRule($this->propertyId, 7, 10);
        $this->taxRule($this->propertyId, 10);

        $range = $this->range('2027-07-05', '2027-07-12');
        $first = $this->c->pricingService()->quote($unit, $range);
        $second = $this->c->pricingService()->quote($unit, $range);

        self::assertSame(51999, $first->nights[4]->price, 'Cuma gecesi 333,33 x 1,3 x 1,2 = 519,99');
        self::assertSame($first->total, $first->linesTotal(), 'Kalemlerin toplamı toplam fiyata eşit');
        self::assertSame($first->stayTotal, array_sum(array_map(fn ($n) => $n->price, $first->nights)));
        self::assertSame($first->total, $second->total, 'Aynı girdi aynı sonucu verir');
        self::assertSame(ChargeType::KonaklamaGecesi, $first->toCharges()[0]->type);
    }
}
