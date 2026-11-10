<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Exception\ValidationException;
use App\Model\BookableUnit;
use App\Model\CalendarEntry;
use App\Model\CalendarStatus;
use App\Model\Property;
use App\Model\PropertyStatus;
use App\Model\ReservationStatus;
use App\Model\Role;
use App\Model\User;
use Tests\Support\AppTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Çekirdek kural (SRS 4.4): doğrulama, engelleyen durumlar, kapalı geceler, alternatifler, takvim. */
final class BookingConflictServiceTest extends AppTestCase
{
    private User $host;
    private User $guest;
    private Property $property;
    private BookableUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = $this->user(Role::IlanSahibi);
        $this->guest = $this->user();
        $this->property = $this->property((int) $this->host->id);
        $this->unit = $this->unit((int) $this->property->id, 1000, 2);
    }

    #[Group('BR-01')]
    #[Group('BR-02')]
    #[Group('BR-14')]
    #[Group('AC-03')]
    #[Group('FR-11')]
    public function testDateRulesBr01Br02Br14(): void
    {
        $conflicts = $this->c->bookingConflictService();

        self::assertArrayHasKey('cikis', $conflicts->dateErrors($this->range('2026-11-10', '2026-11-10')), 'aynı gün giriş-çıkış geçersiz');
        self::assertArrayHasKey('cikis', $conflicts->dateErrors($this->range('2026-11-12', '2026-11-10')), 'çıkış girişten önce');
        self::assertArrayHasKey('giris', $conflicts->dateErrors($this->range('2026-10-31', '2026-11-02')), 'dün için giriş reddedilir');
        self::assertSame([], $conflicts->dateErrors($this->range('2026-11-01', '2026-11-02')), 'bugün için giriş kabul edilir (NFR-17)');
        self::assertArrayHasKey('cikis', $conflicts->dateErrors($this->range('2026-11-01', '2026-12-02')), 'en çok 30 gece');
        self::assertArrayHasKey('giris', $conflicts->dateErrors($this->range('2027-11-02', '2027-11-03')), 'en çok 365 gün ileri');
    }

    #[Group('BR-03')]
    #[Group('BR-19')]
    #[Group('AC-03')]
    #[Group('FR-11')]
    public function testCapacityInactivePropertyAndOwnListing(): void
    {
        $conflicts = $this->c->bookingConflictService();
        try {
            $conflicts->validate($this->property, $this->unit, $this->range('2026-11-10', '2026-11-12'), 3, (int) $this->guest->id);
            self::fail('Kapasite aşımı reddedilmeliydi');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('kisi', $e->errors());
        }

        try {
            $conflicts->validate($this->property, $this->unit, $this->range('2026-11-10', '2026-11-12'), 1, (int) $this->host->id);
            self::fail('İlan sahibi kendi ilanına rezervasyon yapamamalı (BR-19)');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('ilan', $e->errors());
        }

        $this->property->status = PropertyStatus::Pasif;
        $this->expectException(ValidationException::class);
        $conflicts->validate($this->property, $this->unit, $this->range('2026-11-10', '2026-11-12'), 1, (int) $this->guest->id);
    }

    #[Group('BR-12')]
    #[Group('AC-15')]
    #[Group('CS-05')]
    public function testExpiredHoldDoesNotBlockEvenBeforeCleanup(): void
    {
        $hold = $this->book((int) $this->guest->id, $this->unit, '2026-11-10', '2026-11-12');
        $conflicts = $this->c->bookingConflictService();
        $range = $this->range('2026-11-10', '2026-11-12');

        self::assertFalse($conflicts->isAvailable((int) $this->unit->id, $range), 'Beklemede kayıt geceleri tutar');

        // NFR-17: expires_at'ten bir saniye önce geçerli, bir saniye sonra süresi dolmuş
        $this->clock->set($hold->expiresAt?->modify('-1 second') ?? $this->clock->now());
        self::assertTrue($conflicts->isBlocking($hold));
        $this->clock->set($hold->expiresAt?->modify('+1 second') ?? $this->clock->now());
        self::assertFalse($conflicts->isBlocking($hold));
        self::assertTrue($conflicts->isAvailable((int) $this->unit->id, $range), 'Zamanlanmış görev çalışmasa da engellemez (FR-34)');
    }

    #[Group('BR-04')]
    #[Group('BR-22')]
    #[Group('FR-22')]
    public function testClosedNightsBlockBookingButNotOtherUnits(): void
    {
        $this->c->calendar()->upsert(new CalendarEntry(null, (int) $this->unit->id, $this->d('2026-11-11'), CalendarStatus::Kapali, null));
        $other = $this->unit((int) $this->property->id, 1000, 2, '102');
        $conflicts = $this->c->bookingConflictService();

        self::assertFalse($conflicts->isAvailable((int) $this->unit->id, $this->range('2026-11-10', '2026-11-12')));
        self::assertTrue($conflicts->isAvailable((int) $other->id, $this->range('2026-11-10', '2026-11-12')), 'Çakışma birim bazındadır (BR-04)');
        self::assertTrue($conflicts->isAvailable((int) $this->unit->id, $this->range('2026-11-12', '2026-11-14')));
    }

    #[Group('FR-36')]
    #[Group('AC-12')]
    public function testAlternativesHaveSameLengthAndAreFree(): void
    {
        $this->bookAndPay((int) $this->guest->id, $this->unit, '2026-11-10', '2026-11-12');
        $conflicts = $this->c->bookingConflictService();
        $alternatives = $conflicts->suggestAlternatives((int) $this->unit->id, $this->range('2026-11-10', '2026-11-12'));

        self::assertCount(3, $alternatives);
        foreach ($alternatives as $alternative) {
            self::assertSame(2, $alternative->nights());
            self::assertTrue($conflicts->isAvailable((int) $this->unit->id, $alternative));
        }
    }

    #[Group('FR-10')]
    #[Group('AC-32')]
    public function testNightStatusesForCalendar(): void
    {
        $this->bookAndPay((int) $this->guest->id, $this->unit, '2026-11-10', '2026-11-12');
        $this->c->calendar()->upsert(new CalendarEntry(null, (int) $this->unit->id, $this->d('2026-11-20'), CalendarStatus::Kapali, null));

        $statuses = $this->c->bookingConflictService()->nightStatuses((int) $this->unit->id, $this->d('2026-11-01'), $this->d('2026-12-01'));

        self::assertSame('dolu', $statuses['2026-11-10']);
        self::assertSame('dolu', $statuses['2026-11-11']);
        self::assertSame('musait', $statuses['2026-11-12'], 'Çıkış günü yeni girişe açık');
        self::assertSame('kapali', $statuses['2026-11-20']);
        self::assertCount(30, $statuses);
    }

    #[Group('BR-12')]
    #[Group('AC-16')]
    public function testCancelledReservationDoesNotBlock(): void
    {
        $reservation = $this->bookAndPay((int) $this->guest->id, $this->unit, '2026-11-20', '2026-11-22');
        self::assertSame(ReservationStatus::Onaylandi, $reservation->status);
        $this->c->reservationService()->cancelByCustomer($reservation->number, (int) $this->guest->id);

        self::assertTrue($this->c->bookingConflictService()->isAvailable((int) $this->unit->id, $this->range('2026-11-20', '2026-11-22')));
    }
}
