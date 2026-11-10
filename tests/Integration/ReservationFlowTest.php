<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exception\ConflictException;
use App\Exception\InvalidTransitionException;
use App\Exception\PersistenceException;
use App\Exception\PriceChangedException;
use App\Exception\ValidationException;
use App\Model\BookableUnit;
use App\Model\BookingMode;
use App\Model\CalendarStatus;
use App\Model\CancellationPolicy;
use App\Model\Property;
use App\Model\PropertyStatus;
use App\Model\ReservationStatus;
use App\Model\Role;
use App\Model\User;
use App\Service\Dto\CreateReservationRequest;
use App\Service\Dto\SearchCriteria;
use App\Service\State\ReservationState;
use Tests\Support\AppTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * UC-02, UC-04, UC-05 uçtan uca (servis katmanı + JSON depo):
 * T-02, T-03, T-04, T-07, T-08, T-09, T-11 ve CS-04, CS-05, CS-06, CS-09, CS-10, CS-11, CS-12.
 */
final class ReservationFlowTest extends AppTestCase
{
    private User $host;
    private User $guest;
    private User $other;
    private Property $property;
    private BookableUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = $this->user(Role::IlanSahibi);
        $this->guest = $this->user();
        $this->other = $this->user();
        $this->property = $this->property((int) $this->host->id);
        $this->unit = $this->unit((int) $this->property->id, 1000, 2);
        $this->taxRule((int) $this->property->id, 10);
    }

    protected function tearDown(): void
    {
        // T-11: her senaryodan sonra tutarlılık denetimi boş olmalı
        self::assertSame([], $this->c->consistencyAuditService()->violations());
        parent::tearDown();
    }

    private function request(\App\Support\DateRange $range, int $expectedTotal): CreateReservationRequest
    {
        return new CreateReservationRequest(
            (int) $this->guest->id,
            (int) $this->unit->id,
            $range,
            1,
            bin2hex(random_bytes(16)),
            $expectedTotal,
            'A B',
            '0532 111 22 33',
            null,
        );
    }

    #[Group('FR-12')]
    #[Group('FR-13')]
    #[Group('FR-14')]
    #[Group('AC-04')]
    #[Group('BR-15')]
    #[Group('BR-16')]
    #[Group('BR-29')]
    #[Group('UC-02')]
    #[Group('BR-20')]
    public function testCreateStoresPendingHoldWithNightsChargesAndNumber(): void
    {
        $r = $this->book((int) $this->guest->id, $this->unit, '2026-11-10', '2026-11-13', 2);

        self::assertSame(ReservationStatus::Beklemede, $r->status);
        self::assertMatchesRegularExpression('/^RZ-\d{8}-[A-Z0-9]{6}$/', $r->number, 'FR-13');
        self::assertEquals($this->clock->now()->modify('+10 minutes'), $r->expiresAt, 'anında mod: 10 dakika (BR-15)');
        $nights = $this->c->reservations()->nightsOf((int) $r->id);
        self::assertCount(3, $nights);
        self::assertSame([$r->unitId], array_values(array_unique(array_map(fn ($n): int => $n->unitId, $nights))), 'BR-20: rezervasyon tek birime aittir');
        $charges = $this->c->reservations()->chargesOf((int) $r->id);
        self::assertSame($r->totalPrice, array_sum(array_map(fn ($c) => $c->amount, $charges)), 'Kalemler toplamı = toplam (BR-16)');
        self::assertSame(CancellationPolicy::Orta, $r->cancellationPolicy, 'Politika anlık görüntüsü (BR-29)');
    }

    #[Group('BR-05')]
    #[Group('BR-15')]
    #[Group('FR-34')]
    public function testApprovalModeHoldsFor24Hours(): void
    {
        $property = $this->property((int) $this->host->id, BookingMode::Onayli);
        $unit = $this->unit((int) $property->id);
        $r = $this->book((int) $this->guest->id, $unit, '2026-11-10', '2026-11-12');

        self::assertEquals($this->clock->now()->modify('+24 hours'), $r->expiresAt);
    }

    #[Group('FR-18')]
    #[Group('FR-36')]
    #[Group('AC-12')]
    #[Group('BR-11')]
    #[Group('UC-02')]
    public function testConflictIsRejectedWithAlternativesAndNoRecord(): void
    {
        $this->bookAndPay((int) $this->guest->id, $this->unit, '2026-11-10', '2026-11-12');
        $before = count($this->c->reservations()->all());

        try {
            $this->book((int) $this->other->id, $this->unit, '2026-11-11', '2026-11-13');
            self::fail('Çakışma reddedilmeliydi');
        } catch (ConflictException $e) {
            self::assertNotEmpty($e->alternatives());
            self::assertLessThanOrEqual(3, count($e->alternatives()));
            self::assertStringNotContainsString($this->guest->email, $e->getMessage(), 'NFR-04');
        }
        self::assertCount($before, $this->c->reservations()->all(), 'Reddedilen istek kayıt bırakmaz (AC-12)');
    }

    #[Group('BR-11')]
    #[Group('BR-13')]
    #[Group('AC-14')]
    public function testBackToBackStaysAreAllowed(): void
    {
        $this->bookAndPay((int) $this->guest->id, $this->unit, '2026-11-10', '2026-11-12');
        $next = $this->bookAndPay((int) $this->other->id, $this->unit, '2026-11-12', '2026-11-14');
        $previous = $this->bookAndPay((int) $this->other->id, $this->unit, '2026-11-08', '2026-11-10');

        self::assertSame(ReservationStatus::Onaylandi, $next->status);
        self::assertSame(ReservationStatus::Onaylandi, $previous->status);
    }

    #[Group('T-03')]
    #[Group('CS-05')]
    #[Group('AC-15')]
    #[Group('FR-34')]
    #[Group('BR-12')]
    public function testT03LazyExpiryFreesNightsForNewRequest(): void
    {
        $old = $this->book((int) $this->guest->id, $this->unit, '2026-11-10', '2026-11-12');
        $this->clock->advance('+11 minutes'); // bekletme doldu, zamanlanmış görev çalışmadı (CS-05)

        $new = $this->book((int) $this->other->id, $this->unit, '2026-11-10', '2026-11-12');

        self::assertSame(ReservationStatus::Beklemede, $new->status);
        self::assertSame(ReservationStatus::SuresiDoldu, $this->c->reservations()->findById((int) $old->id)?->status);
        self::assertSame([], $this->c->reservations()->nightsOf((int) $old->id));
    }

    #[Group('T-04')]
    #[Group('CS-06')]
    #[Group('AC-17')]
    #[Group('FR-35')]
    public function testT04SameIdempotencyKeyCreatesOneReservation(): void
    {
        $key = str_repeat('a', 32);
        $first = $this->book((int) $this->guest->id, $this->unit, '2026-11-10', '2026-11-12', 1, $key);
        $quote = $this->c->pricingService()->quote($this->unit, $this->range('2026-11-10', '2026-11-12'));
        $second = $this->c->reservationService()->create(new CreateReservationRequest(
            (int) $this->guest->id,
            (int) $this->unit->id,
            $this->range('2026-11-10', '2026-11-12'),
            1,
            $key,
            $quote->total,
            'Test Müşteri',
            '0532 111 22 33',
            null,
        ));

        self::assertTrue($second->duplicate);
        self::assertSame($first->number, $second->reservation->number);
        self::assertCount(1, $this->c->reservations()->all());
    }

    #[Group('T-07')]
    #[Group('CS-12')]
    #[Group('FR-33')]
    #[Group('NFR-05')]
    public function testT07FailureWhileWritingNightsRollsBackEverything(): void
    {
        // Uygulama kontrolünü atlat (CS-12): gecelerini bırakmamış "iptal" bir kayıt, hatalı bir kodun
        // bıraktığı tutarsızlığı taklit eder. Uygulama kuralı bu kaydı engelleyici saymaz; ancak gece tablosundaki
        // UNIQUE(unit_id, night_date) kısıtı (JSON şeması) yazmayı durdurur.
        $ghost = $this->c->tx()->transactional(fn () => $this->c->reservations()->createWithNights(
            new \App\Model\Reservation(
                null,
                'RZ-20261101-HAYALT',
                (int) $this->other->id,
                (int) $this->unit->id,
                $this->d('2026-11-11'),
                $this->d('2026-11-12'),
                1,
                ReservationStatus::IptalEdildi,
                null,
                100000,
                CancellationPolicy::Orta,
                'hayalet-kayit',
                'Hayalet',
                '0532 000 00 00',
                null,
                'test',
                $this->clock->now(),
                0,
                $this->clock->now(),
                $this->clock->now(),
            ),
            [],
            [$this->d('2026-11-11')],
        ));

        try {
            $this->book((int) $this->guest->id, $this->unit, '2026-11-10', '2026-11-12');
            self::fail('Benzersizlik ihlali çakışma olarak bildirilmeliydi');
        } catch (ConflictException) {
        }
        self::assertCount(1, $this->c->reservations()->all(), 'Yeni rezervasyon kaydı geri alındı (yalnızca hayalet kayıt var)');
        self::assertSame([], $this->c->reservations()->findByCustomer((int) $this->guest->id));
        self::assertSame([], $this->c->reservations()->chargesOf((int) $ghost->id + 1), 'Kalemler de geri alındı');
        self::assertCount(1, $this->c->reservations()->allNights(), 'Yalnızca hayalet kaydın gecesi kaldı');

        // T-11 denetimi taklit edilen tutarsızlığı bulur; sonra temizlenir (tearDown denetimi boş olmalı)
        self::assertCount(1, $this->c->consistencyAuditService()->violations());
        $this->c->tx()->transactional(fn (): int => $this->c->reservations()->releaseNights((int) $ghost->id));
    }

    #[Group('CS-04')]
    #[Group('FR-11')]
    #[Group('AC-20')]
    public function testCs04StalePreviewIsRejectedAtConfirmation(): void
    {
        $range = $this->range('2026-11-10', '2026-11-12');
        $preview = $this->c->reservationService()->preview((int) $this->guest->id, (int) $this->unit->id, $range, 1);
        self::assertTrue($preview->available);

        $this->bookAndPay((int) $this->other->id, $this->unit, '2026-11-10', '2026-11-12'); // başkası aynı geceleri aldı

        $this->expectException(ConflictException::class);
        $this->c->reservationService()->create($this->request($range, $preview->quote->total));
    }

    #[Group('FR-14')]
    #[Group('BR-07')]
    #[Group('UC-02')]
    public function testPriceChangeRequiresReconfirmation(): void
    {
        $range = $this->range('2026-11-10', '2026-11-12');
        $preview = $this->c->reservationService()->preview((int) $this->guest->id, (int) $this->unit->id, $range, 1);
        $this->weekendRule((int) $this->property->id, 20); // onay ekranındayken kural değişti
        $this->seasonRule((int) $this->property->id, '2026-11-01', '2026-11-30', 10);

        try {
            $this->c->reservationService()->create($this->request($range, $preview->quote->total));
            self::fail('Fiyat değişikliği yeniden onay istemeli (A5)');
        } catch (PriceChangedException $e) {
            self::assertNotSame($preview->quote->total, $e->newQuote->total);
        }
        self::assertSame([], $this->c->reservations()->all());
    }

    #[Group('CS-09')]
    #[Group('AC-19')]
    #[Group('FR-37')]
    #[Group('BR-05')]
    public function testCs09FailedPaymentKeepsPendingThenRetryConfirms(): void
    {
        $r = $this->book((int) $this->guest->id, $this->unit, '2026-11-10', '2026-11-12');
        $service = $this->c->reservationService();

        $status = fn (): ?ReservationStatus => $this->c->reservations()->findById((int) $r->id)?->status;
        $failed = $service->pay($r->number, (int) $this->guest->id, false);
        self::assertFalse($failed->isCaptured());
        self::assertSame(ReservationStatus::Beklemede, $status(), 'AC-19');

        $service->pay($r->number, (int) $this->guest->id, true);
        self::assertSame(ReservationStatus::Onaylandi, $status());
    }

    #[Group('FR-34')]
    #[Group('FR-37')]
    #[Group('BR-15')]
    public function testPaymentAfterExpiryIsRejectedAndHoldExpires(): void
    {
        $r = $this->book((int) $this->guest->id, $this->unit, '2026-11-10', '2026-11-12');
        $this->clock->advance('+15 minutes');

        try {
            $this->c->reservationService()->pay($r->number, (int) $this->guest->id, true);
            self::fail('Süre dolduktan sonra ödeme alınmamalı');
        } catch (ValidationException) {
        }
        self::assertSame(ReservationStatus::SuresiDoldu, $this->c->reservations()->findById((int) $r->id)?->status);
    }

    #[Group('CS-10')]
    #[Group('FR-23')]
    #[Group('BR-15')]
    #[Group('UC-05')]
    public function testCs10HostCannotApproveAfter24Hours(): void
    {
        $property = $this->property((int) $this->host->id, BookingMode::Onayli);
        $unit = $this->unit((int) $property->id);
        $r = $this->bookAndPay((int) $this->guest->id, $unit, '2026-11-10', '2026-11-12');
        self::assertSame(ReservationStatus::Beklemede, $r->status, 'onaylı modda ödeme sonrası ilan sahibi onayı beklenir');

        $this->clock->advance('+25 hours');
        try {
            $this->c->reservationService()->approve($r->number, (int) $this->host->id);
            self::fail('Süre dolduktan sonra onay reddedilmeli');
        } catch (ValidationException $e) {
            self::assertStringContainsString('süresi doldu', $e->getMessage());
        }
        $expired = $this->c->reservations()->findById((int) $r->id);
        self::assertSame(ReservationStatus::SuresiDoldu, $expired?->status);
        self::assertSame($r->totalPrice, $expired->refundAmount, 'Süre dolumunda ödenen tutarın tamamı iade (BR-28)');
    }

    #[Group('FR-23')]
    #[Group('BR-28')]
    #[Group('AC-27')]
    #[Group('UC-05')]
    public function testHostApproveAndRejectWithFullRefund(): void
    {
        $property = $this->property((int) $this->host->id, BookingMode::Onayli);
        $unit = $this->unit((int) $property->id);
        $a = $this->bookAndPay((int) $this->guest->id, $unit, '2026-11-10', '2026-11-12');
        $b = $this->bookAndPay((int) $this->other->id, $unit, '2026-11-20', '2026-11-22');
        $service = $this->c->reservationService();

        self::assertSame(ReservationStatus::Onaylandi, $service->approve($a->number, (int) $this->host->id)->status);
        $rejected = $service->reject($b->number, (int) $this->host->id, 'Tarihler uygun değil');
        self::assertSame(ReservationStatus::Reddedildi, $rejected->status);
        self::assertSame($b->totalPrice, $this->c->paymentService()->refundedAmount((int) $b->id), 'AC-27');
        self::assertSame([], $this->c->reservations()->nightsOf((int) $b->id));
    }

    #[Group('BR-08')]
    #[Group('AC-07')]
    #[Group('NFR-03')]
    public function testOtherHostCannotManageReservation(): void
    {
        $property = $this->property((int) $this->host->id, BookingMode::Onayli);
        $unit = $this->unit((int) $property->id);
        $r = $this->bookAndPay((int) $this->guest->id, $unit, '2026-11-10', '2026-11-12');
        $stranger = $this->user(Role::IlanSahibi);

        $this->expectException(\App\Exception\ForbiddenException::class);
        $this->c->reservationService()->approve($r->number, (int) $stranger->id);
    }

    #[Group('T-02')]
    #[Group('FR-17')]
    #[Group('FR-45')]
    #[Group('AC-16')]
    #[Group('UC-04')]
    public function testT02CancelReleasesNightsAndDatesBecomeBookableAgain(): void
    {
        $r = $this->bookAndPay((int) $this->guest->id, $this->unit, '2026-11-20', '2026-11-23');
        $quote = $this->c->reservationService()->cancellationQuote($r->number, (int) $this->guest->id);
        self::assertSame(100, $quote->percent, 'Orta politika, 19 gün kala %100');

        $cancelled = $this->c->reservationService()->cancelByCustomer($r->number, (int) $this->guest->id, 'Plan değişti');

        self::assertSame(ReservationStatus::IptalEdildi, $cancelled->status);
        self::assertSame([], $this->c->reservations()->nightsOf((int) $r->id));
        self::assertSame(ReservationStatus::Onaylandi, $this->bookAndPay((int) $this->other->id, $this->unit, '2026-11-20', '2026-11-23')->status, 'AC-16');
    }

    #[Group('T-02')]
    #[Group('FR-15')]
    public function testInvalidTransitionFromFinalStateIsRejected(): void
    {
        $r = $this->bookAndPay((int) $this->guest->id, $this->unit, '2026-11-20', '2026-11-22');
        $this->c->reservationService()->cancelByCustomer($r->number, (int) $this->guest->id);

        try {
            $this->c->tx()->transactional(function () use ($r): void {
                $current = $this->c->reservations()->findById((int) $r->id);
                $this->c->stateMachine()->transition($current ?? $r, ReservationStatus::Onaylandi, null, 'test');
            });
            self::fail('Son durumdan çıkış reddedilmeli');
        } catch (InvalidTransitionException) {
        }
        self::assertSame(ReservationStatus::IptalEdildi, $this->c->reservations()->findById((int) $r->id)?->status);
        self::assertFalse(ReservationState::of(ReservationStatus::IptalEdildi)->canTransitionTo(ReservationStatus::Onaylandi));
    }

    #[Group('BR-18')]
    #[Group('FR-17')]
    public function testCancellationOnCheckInDayIsRejected(): void
    {
        $r = $this->bookAndPay((int) $this->guest->id, $this->unit, '2026-11-03', '2026-11-05');
        $this->clock->set('2026-11-03 09:00:00');

        $this->expectException(ValidationException::class);
        $this->c->reservationService()->cancelByCustomer($r->number, (int) $this->guest->id);
    }

    #[Group('T-08')]
    #[Group('AC-20')]
    #[Group('FR-06')]
    #[Group('FR-10')]
    #[Group('UC-01')]
    public function testT08SearchAndReservationUseTheSameRule(): void
    {
        $second = $this->unit((int) $this->property->id, 1500, 2, '102');
        $this->bookAndPay((int) $this->guest->id, $this->unit, '2026-11-10', '2026-11-12');
        $this->c->availabilityService()->setCalendarRange((int) $this->host->id, (int) $second->id, $this->d('2026-11-10'), $this->d('2026-11-10'), CalendarStatus::Kapali, null);

        $result = $this->c->propertyService()->search(new SearchCriteria(city: 'trabzon', checkIn: '2026-11-10', checkOut: '2026-11-12', guests: 1));
        self::assertSame(0, $result->items->total, 'Dolu veya kapalı birimi olan ilan bu aralıkta çıkmaz (AC-20)');

        $free = $this->c->propertyService()->search(new SearchCriteria(city: 'TRABZON', checkIn: '2026-11-12', checkOut: '2026-11-14', guests: 2));
        self::assertSame(1, $free->items->total);
        $item = $free->items->items[0];
        $booked = $this->bookAndPay((int) $this->other->id, $item->cheapestUnit ?? $this->unit, '2026-11-12', '2026-11-14', 2);
        self::assertSame(ReservationStatus::Onaylandi, $booked->status, 'Aramada çıkan birim rezervasyonda kabul edilir');
        self::assertSame($item->quote?->total, $booked->totalPrice, 'Aramadaki fiyat = rezervasyon fiyatı (FR-44)');
    }

    #[Group('T-09')]
    #[Group('CS-08')]
    #[Group('AC-18')]
    #[Group('BR-22')]
    #[Group('FR-22')]
    #[Group('UC-03')]
    public function testT09HostCannotCloseReservedNights(): void
    {
        $this->bookAndPay((int) $this->guest->id, $this->unit, '2026-11-10', '2026-11-12');
        $availability = $this->c->availabilityService();

        try {
            $availability->setCalendarRange((int) $this->host->id, (int) $this->unit->id, $this->d('2026-11-11'), $this->d('2026-11-15'), CalendarStatus::Kapali, null);
            self::fail('Rezervasyonlu gece kapatılamamalı (AC-18)');
        } catch (ValidationException $e) {
            self::assertStringContainsString('RZ-', $e->getMessage());
        }
        $closed = $availability->setCalendarRange((int) $this->host->id, (int) $this->unit->id, $this->d('2026-11-12'), $this->d('2026-11-14'), CalendarStatus::Kapali, null);
        self::assertSame(3, $closed);
        self::assertFalse($this->c->bookingConflictService()->isAvailable((int) $this->unit->id, $this->range('2026-11-12', '2026-11-13')));
    }

    #[Group('CS-11')]
    #[Group('FR-28')]
    #[Group('FR-39')]
    #[Group('AC-09')]
    #[Group('BR-28')]
    public function testCs11AdminRemovalCancelsFutureReservationsWithFullRefund(): void
    {
        $admin = $this->user(Role::Yonetici);
        $future = $this->bookAndPay((int) $this->guest->id, $this->unit, '2026-11-20', '2026-11-22');

        $count = $this->c->propertyService()->removeByAdmin((int) $admin->id, (int) $this->property->id, 'Kural ihlali tespit edildi');

        self::assertSame(1, $count);
        $cancelled = $this->c->reservations()->findById((int) $future->id);
        self::assertSame(ReservationStatus::IptalEdildi, $cancelled?->status);
        self::assertSame($future->totalPrice, $this->c->paymentService()->refundedAmount((int) $future->id), '%100 iade');
        self::assertSame(PropertyStatus::Kaldirildi, $this->c->properties()->findById((int) $this->property->id)?->status);

        $this->expectException(ValidationException::class);
        $this->book((int) $this->other->id, $this->unit, '2026-11-25', '2026-11-27');
    }

    #[Group('FR-40')]
    #[Group('FR-34')]
    public function testFr40ScheduledTaskCompletesStaysAndExpiresHolds(): void
    {
        $stay = $this->bookAndPay((int) $this->guest->id, $this->unit, '2026-11-02', '2026-11-04');
        $hold = $this->book((int) $this->other->id, $this->unit, '2026-11-10', '2026-11-12');
        $this->clock->set('2026-11-04 08:00:00');

        $result = $this->c->scheduledTaskService()->run();

        self::assertSame(['expired' => 1, 'completed' => 1], $result);
        self::assertSame(ReservationStatus::Tamamlandi, $this->c->reservations()->findById((int) $stay->id)?->status);
        self::assertSame(ReservationStatus::SuresiDoldu, $this->c->reservations()->findById((int) $hold->id)?->status);
        self::assertSame([], $this->c->reservations()->nightsOf((int) $stay->id), 'Tamamlandı: gece kayıtları silinir');
    }

    #[Group('FR-24')]
    #[Group('BR-09')]
    #[Group('BR-21')]
    public function testReviewOnlyForCompletedStayAndOnlyOnce(): void
    {
        $stay = $this->bookAndPay((int) $this->guest->id, $this->unit, '2026-11-02', '2026-11-04');
        $reviews = $this->c->reviewService();
        try {
            $reviews->create((int) $this->guest->id, $stay->number, 5, 'Harika bir konaklamaydı, teşekkürler.');
            self::fail('Tamamlanmamış konaklamaya yorum yapılamaz (BR-09)');
        } catch (ValidationException) {
        }
        $this->clock->set('2026-11-04 13:00:00');
        $this->c->scheduledTaskService()->run();
        $reviews->create((int) $this->guest->id, $stay->number, 5, 'Harika bir konaklamaydı, teşekkürler.');

        $this->expectException(ValidationException::class);
        $reviews->create((int) $this->guest->id, $stay->number, 4, 'İkinci yorum kabul edilmemeli.');
    }

    #[Group('FR-16')]
    #[Group('FR-32')]
    #[Group('AC-06')]
    #[Group('NFR-04')]
    public function testCustomerSeesOnlyOwnReservations(): void
    {
        $r = $this->bookAndPay((int) $this->guest->id, $this->unit, '2026-11-10', '2026-11-12');

        self::assertSame($r->number, $this->c->reservationService()->viewFor($r->number, $this->host)->reservation->number, 'ilgili ilan sahibi görür');
        $this->expectException(\App\Exception\ForbiddenException::class);
        $this->c->reservationService()->viewFor($r->number, $this->other);
    }

    #[Group('FR-33')]
    public function testLockUnitRequiresTransaction(): void
    {
        $this->expectException(PersistenceException::class);
        $this->c->reservations()->lockUnit((int) $this->unit->id);
    }
}
