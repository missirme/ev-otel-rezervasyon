<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exception\ForbiddenException;
use App\Exception\InvalidTransitionException;
use App\Exception\ValidationException;
use App\Model\AuditLog;
use App\Model\BookableUnit;
use App\Model\BookingMode;
use App\Model\Property;
use App\Model\ReservationStatus;
use App\Model\Role;
use App\Model\User;
use App\Repository\ReservationRepositoryInterface;
use App\Service\Dto\CreateReservationRequest;
use App\Service\ReservationService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AppTestCase;

/**
 * ReservationService'in hata ve kenar dalları (NFR-16: çekirdek servislerde en az %90 satır kapsamı).
 * Mutlu yollar ReservationFlowTest'tedir; burada her kuralın reddedildiği durumlar sınanır.
 */
final class ReservationServiceRulesTest extends AppTestCase
{
    private User $host;
    private User $guest;
    private Property $instant;
    private Property $approval;
    private BookableUnit $instantUnit;
    private BookableUnit $approvalUnit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = $this->user(Role::IlanSahibi);
        $this->guest = $this->user();
        $this->instant = $this->property((int) $this->host->id, BookingMode::Aninda);
        $this->approval = $this->property((int) $this->host->id, BookingMode::Onayli);
        $this->instantUnit = $this->unit((int) $this->instant->id, 1000, 2);
        $this->approvalUnit = $this->unit((int) $this->approval->id, 1000, 2);
    }

    private function service(): ReservationService
    {
        return $this->c->reservationService();
    }

    /** @return array<string, string> */
    private function errorsOf(callable $action): array
    {
        try {
            $action();
        } catch (ValidationException $e) {
            return $e->errors();
        }
        self::fail('ValidationException bekleniyordu');
    }

    #[Group('FR-11')]
    #[Group('FR-35')]
    #[Group('NFR-06')]
    public function testCreateValidatesContactFieldsAndFormKey(): void
    {
        $range = $this->range('2026-11-10', '2026-11-12');
        $errors = $this->errorsOf(fn () => $this->service()->create(new CreateReservationRequest(
            (int) $this->guest->id,
            (int) $this->instantUnit->id,
            $range,
            1,
            '',
            $this->c->pricingService()->quote($this->instantUnit, $range)->total,
            '',
            'telefon yok',
            str_repeat('a', 501),
        )));

        self::assertSame(['iletisim_ad', 'iletisim_telefon', 'not', 'form'], array_keys($errors));
        self::assertSame([], $this->c->reservations()->all(), 'Hatalı formda kayıt oluşmaz');
    }

    #[Group('FR-35')]
    #[Group('NFR-04')]
    public function testSameFormKeyFromAnotherCustomerIsForbidden(): void
    {
        $this->book((int) $this->guest->id, $this->instantUnit, '2026-11-10', '2026-11-12', 1, 'ortak-form-anahtari');

        $this->expectException(ForbiddenException::class);
        $this->book((int) $this->user()->id, $this->instantUnit, '2026-11-20', '2026-11-22', 1, 'ortak-form-anahtari');
    }

    #[Group('FR-37')]
    #[Group('BR-05')]
    public function testPaymentIsTakenOnlyOnceAndOnlyForPendingReservations(): void
    {
        $pending = $this->book((int) $this->guest->id, $this->approvalUnit, '2026-11-10', '2026-11-12');
        $this->service()->pay($pending->number, (int) $this->guest->id, true);
        self::assertSame(ReservationStatus::Beklemede, $this->c->reservations()->findById((int) $pending->id)?->status, 'Onaylı mod: ödeme sonrası ilan sahibini bekler');
        self::assertStringContainsString('zaten alınmış', $this->errorsOf(fn () => $this->service()->pay($pending->number, (int) $this->guest->id, true))['odeme']);

        $confirmed = $this->bookAndPay((int) $this->guest->id, $this->instantUnit, '2026-11-10', '2026-11-12');
        self::assertStringContainsString('ödeme alınamaz', $this->errorsOf(fn () => $this->service()->pay($confirmed->number, (int) $this->guest->id, true))['odeme']);
        self::assertCount(2, $this->c->payments()->all(), 'İkinci ödeme kaydı açılmadı');
    }

    #[Group('FR-17')]
    #[Group('FR-34')]
    #[Group('BR-12')]
    public function testCancellingAnExpiredHoldMarksItExpiredInsteadOfCancelled(): void
    {
        $hold = $this->book((int) $this->guest->id, $this->instantUnit, '2026-11-10', '2026-11-12');
        self::assertArrayHasKey('neden', $this->errorsOf(fn () => $this->service()->cancelByCustomer($hold->number, (int) $this->guest->id, str_repeat('x', 501))));

        $this->clock->advance('+11 minutes'); // BR-15: anında modda bekletme 10 dakika
        $errors = $this->errorsOf(fn () => $this->service()->cancelByCustomer($hold->number, (int) $this->guest->id, 'Vazgeçtim'));

        self::assertStringContainsString('bekletme süresi zaten dolmuştu', $errors['iptal']);
        self::assertSame(ReservationStatus::SuresiDoldu, $this->c->reservations()->findById((int) $hold->id)?->status);
        self::assertSame([], $this->c->reservations()->nightsOf((int) $hold->id), 'Geceler serbest');
    }

    #[Group('FR-23')]
    #[Group('UC-05')]
    public function testHostApprovalRules(): void
    {
        $instant = $this->book((int) $this->guest->id, $this->instantUnit, '2026-11-10', '2026-11-12');
        self::assertStringContainsString('anında onay modunda', $this->errorsOf(fn () => $this->service()->approve($instant->number, (int) $this->host->id))['islem']);

        $request = $this->book((int) $this->guest->id, $this->approvalUnit, '2026-11-10', '2026-11-12');
        self::assertStringContainsString('ödemesi henüz alınmadığı', $this->errorsOf(fn () => $this->service()->approve($request->number, (int) $this->host->id))['islem']);

        $this->service()->pay($request->number, (int) $this->guest->id, true);
        self::assertSame(ReservationStatus::Onaylandi, $this->service()->approve($request->number, (int) $this->host->id)->status);
        self::assertStringContainsString('Yalnızca Beklemede', $this->errorsOf(fn () => $this->service()->approve($request->number, (int) $this->host->id))['islem']);
    }

    #[Group('FR-23')]
    #[Group('UC-05')]
    public function testHostRejectionRules(): void
    {
        $request = $this->book((int) $this->guest->id, $this->approvalUnit, '2026-11-10', '2026-11-12');
        self::assertArrayHasKey('neden', $this->errorsOf(fn () => $this->service()->reject($request->number, (int) $this->host->id, 'x')));

        $instant = $this->book((int) $this->guest->id, $this->instantUnit, '2026-11-10', '2026-11-12');
        self::assertStringContainsString('onaylı moddaki', $this->errorsOf(fn () => $this->service()->reject($instant->number, (int) $this->host->id, 'Uygun değil'))['islem']);

        $this->clock->advance('+25 hours'); // BR-15: onaylı modda talep 24 saat bekler
        $errors = $this->errorsOf(fn () => $this->service()->reject($request->number, (int) $this->host->id, 'Geç kaldım'));
        self::assertStringContainsString('süresi zaten dolmuştu', $errors['islem']);
        self::assertSame(ReservationStatus::SuresiDoldu, $this->c->reservations()->findById((int) $request->id)?->status);
    }

    #[Group('FR-39')]
    public function testAdminRemovalSkipsPastStaysAndExpiresStaleHolds(): void
    {
        $admin = $this->user(Role::Yonetici);
        $past = $this->bookAndPay((int) $this->guest->id, $this->instantUnit, '2026-11-02', '2026-11-04');
        $stale = $this->book((int) $this->guest->id, $this->instantUnit, '2026-11-20', '2026-11-22');
        $future = $this->bookAndPay((int) $this->guest->id, $this->instantUnit, '2026-11-25', '2026-11-27');
        $this->clock->set('2026-11-03 09:00:00');

        $cancelled = $this->service()->cancelFutureReservationsOfProperty((int) $this->instant->id, (int) $admin->id, 'Kural ihlali');

        self::assertSame(1, $cancelled, 'Yalnızca gelecekteki etkin rezervasyon iptal edilir');
        self::assertSame(ReservationStatus::Onaylandi, $this->c->reservations()->findById((int) $past->id)?->status, 'Girişi geçmiş konaklamaya dokunulmaz');
        self::assertSame(ReservationStatus::SuresiDoldu, $this->c->reservations()->findById((int) $stale->id)?->status, 'Süresi dolmuş bekletme Süresi Doldu olur');
        self::assertSame(ReservationStatus::IptalEdildi, $this->c->reservations()->findById((int) $future->id)?->status);
    }

    #[Group('FR-16')]
    public function testCustomerListSeparatesUpcomingAndPastReservations(): void
    {
        $later = $this->bookAndPay((int) $this->guest->id, $this->instantUnit, '2026-11-25', '2026-11-27');
        $sooner = $this->bookAndPay((int) $this->guest->id, $this->instantUnit, '2026-11-10', '2026-11-12');
        $cancelled = $this->bookAndPay((int) $this->guest->id, $this->instantUnit, '2026-11-15', '2026-11-17');
        $this->service()->cancelByCustomer($cancelled->number, (int) $this->guest->id);

        $list = $this->service()->listForCustomer((int) $this->guest->id);

        self::assertSame([$sooner->number, $later->number], array_map(fn ($v): string => $v->reservation->number, $list['upcoming']), 'Yaklaşanlar giriş tarihine göre');
        self::assertSame([$cancelled->number], array_map(fn ($v): string => $v->reservation->number, $list['past']));
    }

    /** Sahte (stub) depo ile: tutarsız veri bile geçersiz durum geçişine yol açamaz; deneme denetim kaydına yazılır. */
    #[Group('FR-15')]
    #[Group('FR-41')]
    #[Group('NFR-16')]
    public function testInvalidTransitionFromInconsistentDataIsRejectedAndAudited(): void
    {
        $pending = $this->book((int) $this->guest->id, $this->instantUnit, '2026-11-10', '2026-11-12');
        $broken = $this->createStub(ReservationRepositoryInterface::class);
        $broken->method('findConfirmedCheckedOutBy')->willReturn([$pending]); // hatalı depo: Beklemede kaydı "onaylı" diye döndürür
        $c = $this->c;
        $service = new ReservationService(
            $c->tx(),
            $broken,
            $c->units(),
            $c->properties(),
            $c->users(),
            $c->reviews(),
            $c->bookingConflictService(),
            $c->pricingService(),
            $c->refundService(),
            $c->paymentService(),
            $c->notificationService(),
            $c->stateMachine(),
            $c->auditService(),
            $c->clock,
            $c->settings,
            $c->logger(),
        );

        try {
            $service->completeStays();
            self::fail('Beklemede → Tamamlandı geçişi reddedilmeli');
        } catch (InvalidTransitionException) {
        }
        $attempts = array_filter($c->auditLogs()->all(), fn (AuditLog $l): bool => $l->action === 'gecersiz_gecis');
        self::assertCount(1, $attempts, 'Reddedilen geçiş geri alınan işlemin dışında kaydedildi');
        self::assertSame(ReservationStatus::Beklemede, $c->reservations()->findById((int) $pending->id)?->status);
    }

    #[Group('FR-35')]
    public function testSmallHelpers(): void
    {
        self::assertSame($this->instant->id, $this->service()->propertyIdOfUnit((int) $this->instantUnit->id));
        self::assertNull($this->service()->propertyIdOfUnit(999999));
        $key = $this->service()->newIdempotencyKey();
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $key);
        self::assertNotSame($key, $this->service()->newIdempotencyKey());
    }
}
