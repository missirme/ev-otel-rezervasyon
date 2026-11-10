<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ConflictException;
use App\Exception\ForbiddenException;
use App\Exception\InvalidTransitionException;
use App\Exception\NotFoundException;
use App\Exception\PriceChangedException;
use App\Exception\UniqueConstraintException;
use App\Exception\ValidationException;
use App\Model\BookableUnit;
use App\Model\BookingMode;
use App\Model\Payment;
use App\Model\Property;
use App\Model\Reservation;
use App\Model\ReservationStatus;
use App\Model\Role;
use App\Model\User;
use App\Repository\BookableUnitRepositoryInterface;
use App\Repository\PropertyRepositoryInterface;
use App\Repository\ReservationRepositoryInterface;
use App\Repository\ReviewRepositoryInterface;
use App\Repository\TransactionManagerInterface;
use App\Repository\UserRepositoryInterface;
use App\Service\Dto\CreateReservationRequest;
use App\Service\Dto\CreateReservationResult;
use App\Service\Dto\ReservationPreview;
use App\Service\Dto\ReservationView;
use App\Service\Refund\RefundQuote;
use App\Support\ClockInterface;
use App\Support\DateRange;
use App\Support\Logger;
use App\Support\Money;
use App\Support\Settings;
use App\Support\Text;

/**
 * Rezervasyon akışı (UC-02, UC-04, UC-05). Fiyat için PricingService, çakışma için
 * BookingConflictService, ödeme için PaymentService, durum için ReservationStateMachine kullanılır.
 */
final class ReservationService
{
    private const NUMBER_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    public function __construct(
        private readonly TransactionManagerInterface $tx,
        private readonly ReservationRepositoryInterface $reservations,
        private readonly BookableUnitRepositoryInterface $units,
        private readonly PropertyRepositoryInterface $properties,
        private readonly UserRepositoryInterface $users,
        private readonly ReviewRepositoryInterface $reviews,
        private readonly BookingConflictService $conflicts,
        private readonly PricingService $pricing,
        private readonly RefundService $refunds,
        private readonly PaymentService $payments,
        private readonly NotificationService $notifications,
        private readonly ReservationStateMachine $stateMachine,
        private readonly AuditService $audit,
        private readonly ClockInterface $clock,
        private readonly Settings $settings,
        private readonly Logger $logger,
    ) {
    }

    /** Birimin ait olduğu ilan (hata sonrası ilan sayfasına dönmek için); birim yoksa null. */
    public function propertyIdOfUnit(int $unitId): ?int
    {
        return $this->units->findById($unitId)?->propertyId;
    }

    /** Formun tek kullanımlık anahtarı (FR-35). */
    public function newIdempotencyKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** UC-02 adım 2: doğrulama, ön müsaitlik ve fiyat dökümü. Hiçbir şey yazılmaz. */
    public function preview(?int $customerId, int $unitId, DateRange $range, int $guests): ReservationPreview
    {
        [$unit, $property] = $this->loadUnit($unitId);
        $this->conflicts->validate($property, $unit, $range, $guests, $customerId);
        $quote = $this->pricing->quote($unit, $range);
        $available = $this->conflicts->isAvailable($unitId, $range);

        return new ReservationPreview(
            $property,
            $unit,
            $this->users->findById($property->ownerId),
            $range,
            $guests,
            $quote,
            $available,
            $available ? [] : $this->conflicts->suggestAlternatives($unitId, $range),
            $this->refunds->policyFor($property->cancellationPolicy)->description(),
        );
    }

    /**
     * UC-02 adım 4: tek atomik işlemde birim kilitlenir, süresi dolan bekletmeler temizlenir,
     * kural yeniden doğrulanır, fiyat yeniden hesaplanır; rezervasyon Beklemede olarak
     * kalemleri ve geceleriyle birlikte yazılır (FR-11, 12, 13, 33, 35).
     */
    public function create(CreateReservationRequest $request): CreateReservationResult
    {
        $errors = [];
        if (trim($request->contactName) === '' || Text::length($request->contactName) > 100) {
            $errors['iletisim_ad'] = 'Ad soyad zorunludur (en çok 100 karakter).';
        }
        if (!preg_match('/^\+?[0-9 ()-]{10,20}$/', trim($request->contactPhone))) {
            $errors['iletisim_telefon'] = 'Geçerli bir telefon numarası girin (ör. 0532 123 45 67).';
        }
        if ($request->note !== null && Text::length($request->note) > 500) {
            $errors['not'] = 'Not en çok 500 karakter olabilir.';
        }
        if ($request->idempotencyKey === '' || strlen($request->idempotencyKey) > 64) {
            $errors['form'] = 'Form anahtarı geçersiz; lütfen sayfayı yenileyin.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        try {
            return $this->atomic(function () use ($request): CreateReservationResult {
                // SRS 4.5 adım 1: önce birim satırı kilitlenir (MySQL: SELECT ... FOR UPDATE). Aynı birime gelen
                // eşzamanlı istekler (aynı formun çift gönderimi dahil) buradan sonrasını sırayla yürütür.
                $this->reservations->lockUnit($request->unitId);

                // A4: aynı form ikinci kez gönderildiyse yeni kayıt açılmaz, mevcut rezervasyon gösterilir.
                $existing = $this->reservations->findByIdempotencyKey($request->idempotencyKey);
                if ($existing !== null) {
                    if ($existing->customerId !== $request->customerId) {
                        throw new ForbiddenException();
                    }

                    return new CreateReservationResult($existing, true);
                }

                // İlan ve birim durumu işlem içinde yeniden okunur (CS-11).
                [$unit, $property] = $this->loadUnit($request->unitId);
                $this->conflicts->validate($property, $unit, $request->range, $request->guests, $request->customerId);
                $this->conflicts->assertAvailable((int) $unit->id, $request->range);

                $quote = $this->pricing->quote($unit, $request->range);
                if ($quote->total !== $request->expectedTotal) {
                    throw new PriceChangedException($quote); // A5: yeni döküm ve yeniden onay
                }

                $now = $this->clock->now();
                $expiresAt = $property->bookingMode === BookingMode::Aninda
                    ? $now->modify('+' . $this->settings->holdMinutesInstant . ' minutes')
                    : $now->modify('+' . $this->settings->holdHoursApproval . ' hours');

                $reservation = $this->reservations->createWithNights(
                    new Reservation(
                        null,
                        $this->generateNumber(),
                        $request->customerId,
                        (int) $unit->id,
                        $request->range->checkIn,
                        $request->range->checkOut,
                        $request->guests,
                        ReservationStatus::Beklemede,
                        $expiresAt,
                        $quote->total,
                        $property->cancellationPolicy, // BR-29: politika anlık görüntüsü
                        $request->idempotencyKey,
                        trim($request->contactName),
                        trim($request->contactPhone),
                        $request->note !== null && trim($request->note) !== '' ? trim($request->note) : null,
                        null,
                        null,
                        0,
                        $now,
                        $now,
                    ),
                    $quote->toCharges(),
                    $this->conflicts->reserveNights($request->range),
                );

                $this->audit->log(
                    $request->customerId,
                    'reservation',
                    (int) $reservation->id,
                    'durum_gecisi',
                    null,
                    ReservationStatus::Beklemede->value,
                    'Rezervasyon oluşturuldu'
                );
                $this->notifications->notify(
                    $request->customerId,
                    'rezervasyon_olusturuldu',
                    sprintf('%s numaralı rezervasyonunuz oluşturuldu (%s, %s). Durum: Beklemede.', $reservation->number, $property->name, $request->range)
                );
                if ($property->bookingMode === BookingMode::Onayli) {
                    $this->notifications->notify(
                        $property->ownerId,
                        'yeni_talep',
                        sprintf('%s ilanınız için yeni rezervasyon talebi: %s (%s). 24 saat içinde yanıtlayın.', $property->name, $reservation->number, $request->range)
                    );
                }
                $this->logger->info('rezervasyon_olusturuldu', ['rezervasyon' => $reservation->id, 'birim' => $unit->id]);

                return new CreateReservationResult($reservation, false);
            });
        } catch (UniqueConstraintException $e) {
            // Son savunma (SRS 4.5): UNIQUE(birim, gece) ihlali çakışma olarak bildirilir; hiçbir kayıt kalmaz.
            $this->logger->warning('benzersizlik_ihlali', ['tablo' => $e->table]);
            throw new ConflictException('Seçtiğiniz tarihler artık müsait değil.');
        }
    }

    /** Simüle ödeme (FR-37, CS-09). Başarısızsa rezervasyon Beklemede kalır ve tekrar denenebilir. */
    public function pay(string $number, int $customerId, bool $succeed): Payment
    {
        $outcome = $this->atomic(function () use ($number, $customerId, $succeed): Payment|string {
            $reservation = $this->findOwned($number, $customerId);
            if ($reservation->status !== ReservationStatus::Beklemede) {
                return 'Bu rezervasyon için ödeme alınamaz (durum: ' . $reservation->status->label() . ').';
            }
            if (!$this->conflicts->isBlocking($reservation)) {
                $this->conflicts->expireHold($reservation);

                return 'Bekletme süresi dolduğu için ödeme alınamadı; geceler serbest bırakıldı. Lütfen yeniden rezervasyon yapın.';
            }
            $id = (int) $reservation->id;
            if ($this->payments->hasCapturedPayment($id)) {
                return 'Bu rezervasyonun ödemesi zaten alınmış.';
            }
            [, $property] = $this->loadUnit($reservation->unitId);
            $payment = $this->payments->simulate($reservation, $succeed);
            if (!$succeed) {
                $this->logger->info('odeme_basarisiz', ['rezervasyon' => $id]);

                return $payment;
            }
            if ($property->bookingMode === BookingMode::Aninda) {
                $this->stateMachine->transition($reservation, ReservationStatus::Onaylandi, null, 'Simüle ödeme başarılı');
                $this->notifications->notify($customerId, 'rezervasyon_onaylandi', sprintf('%s numaralı rezervasyonunuz onaylandı.', $number));
                $this->notifications->notify($property->ownerId, 'yeni_rezervasyon', sprintf(
                    '%s ilanınız için yeni onaylı rezervasyon: %s (%s).',
                    $property->name,
                    $number,
                    $reservation->range()
                ));
            } else {
                $this->notifications->notify($property->ownerId, 'odeme_alindi', sprintf('%s numaralı talebin ödemesi alındı; onayınızı bekliyor.', $number));
            }

            return $payment;
        });
        if (is_string($outcome)) {
            throw ValidationException::single('odeme', $outcome);
        }

        return $outcome;
    }

    /** UC-04 adım 2: iptal önizlemesi (kalan gün, oran, iade, kesinti). */
    public function cancellationQuote(string $number, int $customerId): RefundQuote
    {
        $reservation = $this->findOwned($number, $customerId);

        return $this->refunds->quoteCustomerCancellation(
            $reservation,
            $this->payments->paidAmount((int) $reservation->id),
            $this->clock->today()
        );
    }

    /** UC-04: müşteri iptali. Durum, geceler, iade ve denetim kaydı tek işlemde (FR-17). */
    public function cancelByCustomer(string $number, int $customerId, string $reason = ''): Reservation
    {
        $reason = trim($reason) !== '' ? trim($reason) : 'Müşteri iptali';
        if (Text::length($reason) > 500) {
            throw ValidationException::single('neden', 'İptal nedeni en çok 500 karakter olabilir.');
        }
        $outcome = $this->atomic(function () use ($number, $customerId, $reason): Reservation|string {
            $reservation = $this->findOwned($number, $customerId);
            if ($reservation->status === ReservationStatus::Beklemede && !$this->conflicts->isBlocking($reservation)) {
                $this->conflicts->expireHold($reservation);

                return 'Bu rezervasyonun bekletme süresi zaten dolmuştu; Süresi Doldu olarak işaretlendi.';
            }
            $id = (int) $reservation->id;
            $quote = $this->refunds->quoteCustomerCancellation($reservation, $this->payments->paidAmount($id), $this->clock->today());
            if (!$quote->allowed) {
                return $quote->message;
            }
            $updated = $this->stateMachine->transition($reservation, ReservationStatus::IptalEdildi, $customerId, $reason, [
                'cancel_reason' => $reason,
                'cancelled_at' => $this->clock->now(),
                'refund_amount' => $quote->refund,
            ]);
            $this->payments->refund($id, $quote->refund);
            [, $property] = $this->loadUnit($reservation->unitId);
            $this->notifications->notify($customerId, 'iptal', sprintf(
                '%s numaralı rezervasyonunuz iptal edildi. İade: %s, kesinti: %s (simüle).',
                $number,
                Money::format($quote->refund),
                Money::format($quote->deduction)
            ));
            $this->notifications->notify($property->ownerId, 'iptal', sprintf('%s numaralı rezervasyon müşteri tarafından iptal edildi; geceler yeniden müsait.', $number));

            return $updated;
        });
        if (is_string($outcome)) {
            throw ValidationException::single('iptal', $outcome);
        }

        return $outcome;
    }

    /** UC-05: ilan sahibi onayı. Süre dolduysa onay reddedilir ve kayıt Süresi Doldu olur (CS-10). */
    public function approve(string $number, int $hostId): Reservation
    {
        $outcome = $this->atomic(function () use ($number, $hostId): Reservation|string {
            [$reservation, , $property] = $this->loadForHost($number, $hostId);
            if ($property->bookingMode !== BookingMode::Onayli) {
                return 'Bu ilan anında onay modunda; rezervasyonlar ödemeyle kesinleşir.';
            }
            if ($reservation->status !== ReservationStatus::Beklemede) {
                return 'Yalnızca Beklemede talepler onaylanabilir.';
            }
            if (!$this->conflicts->isBlocking($reservation)) {
                $this->conflicts->expireHold($reservation);

                return 'Talep süresi doldu; onay verilemez. Rezervasyon Süresi Doldu durumuna alındı.';
            }
            if (!$this->payments->hasCapturedPayment((int) $reservation->id)) {
                return 'Müşterinin ödemesi henüz alınmadığı için talep onaylanamaz.';
            }
            $updated = $this->stateMachine->transition($reservation, ReservationStatus::Onaylandi, $hostId, 'İlan sahibi onayladı');
            $this->notifications->notify($reservation->customerId, 'rezervasyon_onaylandi', sprintf('%s numaralı rezervasyonunuz ilan sahibi tarafından onaylandı.', $number));

            return $updated;
        });
        if (is_string($outcome)) {
            throw ValidationException::single('islem', $outcome);
        }

        return $outcome;
    }

    /** UC-05: ret. Geceler serbest kalır, ödenen tutarın tamamı iade edilir (BR-28). */
    public function reject(string $number, int $hostId, string $reason): Reservation
    {
        $reason = trim($reason);
        if (Text::length($reason) < 3 || Text::length($reason) > 500) {
            throw ValidationException::single('neden', 'Ret nedeni yazmalısınız (3-500 karakter).');
        }
        $outcome = $this->atomic(function () use ($number, $hostId, $reason): Reservation|string {
            [$reservation, , $property] = $this->loadForHost($number, $hostId);
            if ($property->bookingMode !== BookingMode::Onayli || $reservation->status !== ReservationStatus::Beklemede) {
                return 'Yalnızca onaylı moddaki Beklemede talepler reddedilebilir.';
            }
            if (!$this->conflicts->isBlocking($reservation)) {
                $this->conflicts->expireHold($reservation);

                return 'Talep süresi zaten dolmuştu; Süresi Doldu durumuna alındı.';
            }
            $id = (int) $reservation->id;
            $paid = $this->payments->paidAmount($id);
            $updated = $this->stateMachine->transition($reservation, ReservationStatus::Reddedildi, $hostId, $reason, [
                'cancel_reason' => $reason,
                'refund_amount' => $paid,
            ]);
            $this->payments->refund($id, $paid);
            $this->notifications->notify($reservation->customerId, 'reddedildi', sprintf(
                '%s numaralı talebiniz reddedildi. Neden: %s.%s',
                $number,
                $reason,
                $paid > 0 ? ' Ödenen ' . Money::format($paid) . ' iade edildi (simüle).' : ''
            ));

            return $updated;
        });
        if (is_string($outcome)) {
            throw ValidationException::single('islem', $outcome);
        }

        return $outcome;
    }

    /**
     * FR-39: yönetici ilanı kaldırınca gelecekteki Beklemede/Onaylandı rezervasyonlar iptal edilir,
     * geceler serbest kalır, ödenen tutarın tamamı iade edilir ve müşteriler bilgilendirilir.
     * Çağıranın işlemi içinde çalışır.
     */
    public function cancelFutureReservationsOfProperty(int $propertyId, int $adminId, string $reason): int
    {
        return $this->atomic(function () use ($propertyId, $adminId, $reason): int {
            $unitIds = array_map(fn (BookableUnit $u): int => (int) $u->id, $this->units->findByProperty($propertyId));
            $today = $this->clock->today();
            $count = 0;
            foreach ($this->reservations->findByUnits($unitIds) as $reservation) {
                if (!in_array($reservation->status, [ReservationStatus::Beklemede, ReservationStatus::Onaylandi], true) || $reservation->checkIn < $today) {
                    continue;
                }
                if (!$this->conflicts->isBlocking($reservation)) {
                    $this->conflicts->expireHold($reservation);
                    continue;
                }
                $id = (int) $reservation->id;
                $paid = $this->payments->paidAmount($id);
                $this->stateMachine->transition($reservation, ReservationStatus::IptalEdildi, $adminId, $reason, [
                    'cancel_reason' => 'Yönetici: ' . $reason,
                    'cancelled_at' => $this->clock->now(),
                    'refund_amount' => $paid,
                ]);
                $this->payments->refund($id, $paid);
                $this->notifications->notify($reservation->customerId, 'iptal', sprintf(
                    'İlan yayından kaldırıldığı için %s numaralı rezervasyonunuz iptal edildi. Ödenen tutarın tamamı (%s) iade edildi (simüle).',
                    $reservation->number,
                    Money::format($paid)
                ));
                $count++;
            }

            return $count;
        });
    }

    /** Zamanlanmış görev: süresi dolan bekletmeler (FR-34). */
    public function expireHolds(): int
    {
        return $this->atomic(fn (): int => $this->conflicts->expireStaleHolds());
    }

    /** Zamanlanmış görev: çıkış tarihi gelen Onaylandı rezervasyonlar Tamamlandı olur (FR-40). */
    public function completeStays(): int
    {
        return $this->atomic(function (): int {
            $count = 0;
            foreach ($this->reservations->findConfirmedCheckedOutBy($this->clock->today()) as $reservation) {
                $this->stateMachine->transition($reservation, ReservationStatus::Tamamlandi, null, 'Çıkış tarihi geçti');
                $this->notifications->notify($reservation->customerId, 'tamamlandi', sprintf(
                    '%s numaralı konaklamanız tamamlandı. Deneyiminizi puanlayabilirsiniz.',
                    $reservation->number
                ));
                $count++;
            }

            return $count;
        });
    }

    /** Rezervasyonu yalnızca müşterisi, ilgili ilanın sahibi ve yönetici görebilir (FR-32, NFR-04). */
    public function viewFor(string $number, User $viewer): ReservationView
    {
        $reservation = $this->reservations->findByNumber($number) ?? throw new NotFoundException('Rezervasyon bulunamadı.');
        [$unit, $property] = $this->loadUnit($reservation->unitId);
        $isCustomer = $viewer->id === $reservation->customerId;
        if (!$isCustomer && $viewer->id !== $property->ownerId && $viewer->role !== Role::Yonetici) {
            throw new ForbiddenException('Bu rezervasyonu görüntüleme yetkiniz yok.');
        }
        $id = (int) $reservation->id;
        $paid = $this->payments->paidAmount($id);
        $review = $this->reviews->findByReservation($id);
        $isHold = $reservation->status === ReservationStatus::Beklemede && $this->conflicts->isBlocking($reservation);
        $active = in_array($reservation->status, [ReservationStatus::Beklemede, ReservationStatus::Onaylandi], true);

        return new ReservationView(
            $reservation,
            $unit,
            $property,
            $this->users->findById($reservation->customerId),
            $this->users->findById($property->ownerId),
            $this->reservations->chargesOf($id),
            $paid,
            $this->payments->refundedAmount($id),
            $review,
            $isCustomer && $isHold && $paid === 0,
            $isCustomer && $active && $reservation->checkIn > $this->clock->today(),
            $isCustomer && $reservation->status === ReservationStatus::Tamamlandi && $review === null,
            $isHold,
        );
    }

    /**
     * FR-16: müşterinin gelecek ve geçmiş rezervasyonları.
     *
     * @return array{upcoming: list<ReservationView>, past: list<ReservationView>}
     */
    public function listForCustomer(int $customerId): array
    {
        $today = $this->clock->today();
        $upcoming = [];
        $past = [];
        foreach ($this->reservations->findByCustomer($customerId) as $reservation) {
            $view = $this->summary($reservation);
            $isActive = in_array($reservation->status, [ReservationStatus::Beklemede, ReservationStatus::Onaylandi], true);
            if ($isActive && $reservation->checkOut > $today) {
                $upcoming[] = $view;
            } else {
                $past[] = $view;
            }
        }
        usort($upcoming, fn (ReservationView $a, ReservationView $b): int => $a->reservation->checkIn <=> $b->reservation->checkIn);
        usort($past, fn (ReservationView $a, ReservationView $b): int => $b->reservation->checkIn <=> $a->reservation->checkIn);

        return ['upcoming' => $upcoming, 'past' => $past];
    }

    /**
     * FR-23: ilan sahibinin yalnızca kendi ilanlarına ait rezervasyonları (BR-08).
     *
     * @return list<ReservationView>
     */
    public function listForHost(int $hostId, ?ReservationStatus $status = null): array
    {
        $unitIds = [];
        foreach ($this->properties->findByOwner($hostId) as $property) {
            foreach ($this->units->findByProperty((int) $property->id) as $unit) {
                $unitIds[] = (int) $unit->id;
            }
        }
        $views = [];
        foreach ($this->reservations->findByUnits($unitIds) as $reservation) {
            if ($status !== null && $reservation->status !== $status) {
                continue;
            }
            $views[] = $this->summary($reservation);
        }
        usort($views, function (ReservationView $a, ReservationView $b): int {
            $pa = $a->isHold ? 0 : 1;
            $pb = $b->isHold ? 0 : 1;

            return [$pa, $a->reservation->checkIn] <=> [$pb, $b->reservation->checkIn];
        });

        return $views;
    }

    // ------------------------------------------------------------------ yardımcılar

    /**
     * İşlemi çalıştırır; izinsiz durum geçişi denemesi geri alınan işlemin dışında denetim kaydına yazılır (FR-15, FR-41).
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function atomic(callable $fn): mixed
    {
        try {
            return $this->tx->transactional($fn);
        } catch (InvalidTransitionException $e) {
            $this->tx->transactional(fn () => $this->audit->log(
                $e->actorId,
                'reservation',
                $e->reservationId,
                'gecersiz_gecis',
                $e->from->value,
                $e->to->value,
                'Reddedilen durum geçişi'
            ));
            throw $e;
        }
    }

    private function summary(Reservation $reservation): ReservationView
    {
        [$unit, $property] = $this->loadUnit($reservation->unitId);
        $isHold = $reservation->status === ReservationStatus::Beklemede && $this->conflicts->isBlocking($reservation);

        return new ReservationView(
            $reservation,
            $unit,
            $property,
            $this->users->findById($reservation->customerId),
            null,
            [],
            $this->payments->paidAmount((int) $reservation->id),
            0,
            null,
            false,
            false,
            false,
            $isHold,
        );
    }

    /** @return array{0: BookableUnit, 1: Property} */
    private function loadUnit(int $unitId): array
    {
        $unit = $this->units->findById($unitId) ?? throw new NotFoundException('Oda bulunamadı.');
        $property = $this->properties->findById($unit->propertyId) ?? throw new NotFoundException('İlan bulunamadı.');

        return [$unit, $property];
    }

    private function findOwned(string $number, int $customerId): Reservation
    {
        $reservation = $this->reservations->findByNumber($number) ?? throw new NotFoundException('Rezervasyon bulunamadı.');
        if ($reservation->customerId !== $customerId) {
            throw new ForbiddenException('Bu rezervasyon size ait değil.');
        }

        return $reservation;
    }

    /** @return array{0: Reservation, 1: BookableUnit, 2: Property} */
    private function loadForHost(string $number, int $hostId): array
    {
        $reservation = $this->reservations->findByNumber($number) ?? throw new NotFoundException('Rezervasyon bulunamadı.');
        [$unit, $property] = $this->loadUnit($reservation->unitId);
        if ($property->ownerId !== $hostId) {
            throw new ForbiddenException('Yalnızca kendi ilanınıza ait rezervasyonları yönetebilirsiniz.');
        }

        return [$reservation, $unit, $property];
    }

    /** FR-13: RZ-YYYYMMDD-XXXXXX; numara çakışırsa yeniden üretilir. */
    private function generateNumber(): string
    {
        $date = $this->clock->now()->format('Ymd');
        do {
            $suffix = '';
            for ($i = 0; $i < 6; $i++) {
                $suffix .= self::NUMBER_ALPHABET[random_int(0, strlen(self::NUMBER_ALPHABET) - 1)];
            }
            $number = 'RZ-' . $date . '-' . $suffix;
        } while ($this->reservations->numberExists($number));

        return $number;
    }
}
