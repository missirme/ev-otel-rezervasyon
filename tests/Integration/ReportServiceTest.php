<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Model\Role;
use Tests\Support\AppTestCase;
use PHPUnit\Framework\Attributes\Group;

/** T-15 (BR-30, AC-30): bilinen veriyle elle hesaplanmış doluluk ve net gelir. */
#[Group('FR-30')]
#[Group('BR-30')]
final class ReportServiceTest extends AppTestCase
{
    #[Group('T-15')]
    #[Group('AC-30')]
    public function testOccupancyAndNetRevenueMatchManualCalculation(): void
    {
        $hostA = $this->user(Role::IlanSahibi);
        $hostB = $this->user(Role::IlanSahibi);
        $guest = $this->user();
        $propertyA = $this->property((int) $hostA->id);
        $u1 = $this->unit((int) $propertyA->id, 1000, 2, 'A1');
        $u2 = $this->unit((int) $propertyA->id, 1000, 2, 'A2');
        $u3 = $this->unit((int) $this->property((int) $hostB->id)->id, 1000, 2, 'B1');

        // Kasım 2026 (30 gece) için:
        $this->bookAndPay((int) $guest->id, $u1, '2026-11-05', '2026-11-10');          // 5 gece, 5.000 TL ödendi
        $this->bookAndPay((int) $guest->id, $u2, '2026-11-28', '2026-12-03');          // dönemle kesişen 3 gece, 5.000 TL
        $cancel = $this->bookAndPay((int) $guest->id, $u1, '2026-11-20', '2026-11-22'); // iptal: 2.000 TL ödendi
        $this->c->reservationService()->cancelByCustomer($cancel->number, (int) $guest->id); // 19 gün kala, Orta: %100 iade
        $this->book((int) $guest->id, $u1, '2026-11-24', '2026-11-26');                  // ödenmemiş Beklemede: doluluğa girmez
        $this->bookAndPay((int) $guest->id, $u3, '2026-11-10', '2026-11-20');          // başka ilan sahibine ait

        $reports = $this->c->reportService();
        $own = $reports->build((int) $hostA->id, $this->d('2026-11-01'), $this->d('2026-11-30'));

        // Doluluk = (5 + 3) / (2 birim x 30 gece) x 100 = %13,33
        self::assertSame(30, $own->periodNights);
        self::assertSame(8, $own->total->occupiedNights);
        self::assertSame(60, $own->total->capacityNights);
        self::assertSame(1333, $own->total->occupancyBp);
        // Net gelir = 5.000 + 5.000 + 2.000 − 2.000 iade = 10.000 TL (girişi Kasım'da olanlar)
        self::assertSame(1000000, $own->total->netRevenue);
        self::assertSame(4, $own->total->reservationCount);
        self::assertCount(1, $own->byProperty, 'İlan sahibi yalnızca kendi ilanlarını görür');

        $all = $reports->build(null, $this->d('2026-11-01'), $this->d('2026-11-30'));
        self::assertSame(18, $all->total->occupiedNights, 'Yönetici tüm ilanları görür (+10 gece)');
        self::assertCount(2, $all->byProperty);
        self::assertNotEmpty($reports->toCsvRows($all));
    }

    /** Rapor verisi JOIN'li tek sorgudan gelir: rezervasyon ⋈ birim ⋈ ilan ⟕ ödemeler (her iki depoda aynı sonuç). */
    public function testReportRowsJoinReservationsWithTheirPayments(): void
    {
        $host = $this->user(Role::IlanSahibi);
        $otherHost = $this->user(Role::IlanSahibi);
        $guest = $this->user();
        $unit = $this->unit((int) $this->property((int) $host->id)->id, 1000, 2, 'A1');
        $foreign = $this->unit((int) $this->property((int) $otherHost->id)->id, 1000, 2, 'B1');

        $paid = $this->bookAndPay((int) $guest->id, $unit, '2026-11-05', '2026-11-08');      // 3.000 TL ödendi
        $refunded = $this->bookAndPay((int) $guest->id, $unit, '2026-11-20', '2026-11-22');  // 2.000 TL ödendi, sonra iade
        $this->c->reservationService()->cancelByCustomer($refunded->number, (int) $guest->id);
        $unpaid = $this->book((int) $guest->id, $unit, '2026-11-25', '2026-11-27');           // ödemesiz
        $this->bookAndPay((int) $guest->id, $unit, '2026-12-10', '2026-12-12');               // dönem dışı
        $this->bookAndPay((int) $guest->id, $foreign, '2026-11-05', '2026-11-08');            // başka ilan sahibi

        $rows = $this->c->reservations()->reportRows((int) $host->id, $this->range('2026-11-01', '2026-12-01'));
        $byId = [];
        foreach ($rows as $row) {
            $byId[$row->reservationId] = $row;
        }

        self::assertSame([(int) $paid->id, (int) $refunded->id, (int) $unpaid->id], array_keys($byId), 'Dönem ve ilan sahibi süzgeci');
        self::assertSame([300000, 0], [$byId[(int) $paid->id]->paid, $byId[(int) $paid->id]->refunded]);
        self::assertSame([200000, 200000], [$byId[(int) $refunded->id]->paid, $byId[(int) $refunded->id]->refunded], 'Orta politika, 19 gün kala %100 iade');
        self::assertSame([0, 0], [$byId[(int) $unpaid->id]->paid, $byId[(int) $unpaid->id]->refunded], 'LEFT JOIN: ödemesi olmayan da gelir');
        self::assertCount(4, $this->c->reservations()->reportRows(null, $this->range('2026-11-01', '2026-12-01')), 'Yönetici: tüm ilanlar');
    }
}
