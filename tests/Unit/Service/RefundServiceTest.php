<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Model\CancellationPolicy;
use App\Model\Reservation;
use App\Model\ReservationStatus;
use App\Service\RefundService;
use App\Support\DateRange;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/** T-14 (SRS 4.11, AC-26 - AC-28): iade matrisi, eşik günleri ve politika anlık görüntüsü. */
#[Group('T-14')]
#[Group('FR-45')]
final class RefundServiceTest extends TestCase
{
    private const PAID = 952380; // 9.523,80 TL

    private function reservation(CancellationPolicy $policy, string $checkIn, ReservationStatus $status = ReservationStatus::Onaylandi): Reservation
    {
        $in = DateRange::parseDate($checkIn);
        $now = new DateTimeImmutable('2026-11-01 10:00:00');

        return new Reservation(
            1,
            'RZ-20261101-AAAAAA',
            1,
            1,
            $in,
            $in->modify('+7 days'),
            2,
            $status,
            null,
            self::PAID,
            $policy,
            'k',
            'A',
            '0532',
            null,
            null,
            null,
            0,
            $now,
            $now,
        );
    }

    /** @return array<string, array{CancellationPolicy, int, int}> politika, kalan gün, beklenen oran */
    public static function thresholds(): array
    {
        return [
            'Katı 14 gün: %50' => [CancellationPolicy::Kati, 14, 50],
            'Katı 13 gün: %0' => [CancellationPolicy::Kati, 13, 0],
            'Orta 5 gün: %100' => [CancellationPolicy::Orta, 5, 100],
            'Orta 4 gün: %50' => [CancellationPolicy::Orta, 4, 50],
            'Orta 1 gün: %50' => [CancellationPolicy::Orta, 1, 50],
            'Esnek 1 gün: %100' => [CancellationPolicy::Esnek, 1, 100],
            'Esnek 2 gün: %100' => [CancellationPolicy::Esnek, 2, 100],
        ];
    }

    #[DataProvider('thresholds')]
    #[Group('AC-26')]
    #[Group('BR-17')]
    public function testThresholdDays(CancellationPolicy $policy, int $daysLeft, int $percent): void
    {
        $today = new DateTimeImmutable('2026-11-01');
        $reservation = $this->reservation($policy, $today->modify("+$daysLeft days")->format('Y-m-d'));

        $quote = (new RefundService())->quoteCustomerCancellation($reservation, self::PAID, $today);

        self::assertTrue($quote->allowed);
        self::assertSame($daysLeft, $quote->daysLeft);
        self::assertSame($percent, $quote->percent);
        self::assertSame($quote->paid, $quote->refund + $quote->deduction);
        self::assertLessThanOrEqual(self::PAID, $quote->refund, 'İade ödenen tutarı aşamaz');
    }

    #[Group('BR-27')]
    public function testSrsExampleAmounts(): void
    {
        $today = new DateTimeImmutable('2026-11-01');
        $refunds = new RefundService();

        $moderate3 = $refunds->quoteCustomerCancellation($this->reservation(CancellationPolicy::Orta, '2026-11-04'), self::PAID, $today);
        self::assertSame([476190, 476190], [$moderate3->refund, $moderate3->deduction]); // 4.761,90 / 4.761,90

        $strict10 = $refunds->quoteCustomerCancellation($this->reservation(CancellationPolicy::Kati, '2026-11-11'), self::PAID, $today);
        self::assertSame([0, self::PAID], [$strict10->refund, $strict10->deduction]);
    }

    #[Group('BR-18')]
    public function testCheckInDayAndFinalStatesCannotBeCancelled(): void
    {
        $today = new DateTimeImmutable('2026-11-01');
        $refunds = new RefundService();

        self::assertFalse($refunds->quoteCustomerCancellation($this->reservation(CancellationPolicy::Esnek, '2026-11-01'), self::PAID, $today)->allowed, 'giriş günü');
        self::assertFalse($refunds->quoteCustomerCancellation($this->reservation(CancellationPolicy::Esnek, '2026-10-30'), self::PAID, $today)->allowed, 'giriş sonrası');
        $completed = $this->reservation(CancellationPolicy::Esnek, '2026-12-01', ReservationStatus::Tamamlandi);
        self::assertFalse($refunds->quoteCustomerCancellation($completed, self::PAID, $today)->allowed, 'son durum');
    }

    #[Group('AC-27')]
    public function testUnpaidPendingCancellationHasNoRefundOrDeduction(): void
    {
        $quote = (new RefundService())->quoteCustomerCancellation(
            $this->reservation(CancellationPolicy::Kati, '2026-11-03', ReservationStatus::Beklemede),
            0,
            new DateTimeImmutable('2026-11-01')
        );

        self::assertTrue($quote->allowed);
        self::assertSame([0, 0], [$quote->refund, $quote->deduction]);
    }

    #[Group('BR-29')]
    #[Group('AC-28')]
    public function testSnapshotPolicyIsUsedNotTheCurrentPropertyPolicy(): void
    {
        // Rezervasyon Esnek politikayla yapılmış; ilan sonradan Katı'ya geçse de hesap Esnek'e göre (AC-28)
        $quote = (new RefundService())->quoteCustomerCancellation(
            $this->reservation(CancellationPolicy::Esnek, '2026-11-04'),
            self::PAID,
            new DateTimeImmutable('2026-11-01')
        );

        self::assertSame(100, $quote->percent);
    }

    #[Group('BR-28')]
    #[Group('AC-27')]
    public function testFullRefundForHostRejectAdminCancelAndExpiry(): void
    {
        $quote = (new RefundService())->fullRefund(self::PAID);

        self::assertSame(self::PAID, $quote->refund);
        self::assertSame(0, $quote->deduction);
    }
}
