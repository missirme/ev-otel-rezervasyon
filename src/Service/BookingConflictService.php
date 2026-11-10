<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ConflictException;
use App\Exception\ValidationException;
use App\Model\BookableUnit;
use App\Model\CalendarStatus;
use App\Model\Property;
use App\Model\Reservation;
use App\Model\ReservationStatus;
use App\Model\UnitStatus;
use App\Repository\CalendarRepositoryInterface;
use App\Repository\ReservationRepositoryInterface;
use App\Support\ClockInterface;
use App\Support\DateRange;
use App\Support\Logger;
use App\Support\Money;
use App\Support\Settings;
use DateTimeImmutable;

/**
 * ÇEKİRDEK: tarih çakışması ve çift rezervasyon önleme (SRS 4.2 - 4.5).
 * Bölüm 4.4 karar kuralının TEK sahibidir; arama, takvim ve rezervasyon bu sınıfı kullanır (risk R3).
 */
final class BookingConflictService
{
    public function __construct(
        private readonly ReservationRepositoryInterface $reservations,
        private readonly CalendarRepositoryInterface $calendar,
        private readonly ReservationStateMachine $stateMachine,
        private readonly PaymentService $payments,
        private readonly NotificationService $notifications,
        private readonly ClockInterface $clock,
        private readonly Settings $settings,
        private readonly Logger $logger,
    ) {
    }

    /**
     * BR-12: yalnızca Beklemede (expires_at geçmemiş) ve Onaylandı geceleri engeller.
     * Süresi dolmuş bekletme, zamanlanmış görev çalışmamış olsa bile engellemez (FR-34).
     */
    public function isBlocking(Reservation $reservation, ?DateTimeImmutable $now = null): bool
    {
        $now ??= $this->clock->now();

        return match ($reservation->status) {
            ReservationStatus::Onaylandi => true,
            ReservationStatus::Beklemede => $reservation->expiresAt === null || $reservation->expiresAt > $now,
            default => false,
        };
    }

    /**
     * Tarih kuralları (BR-01, BR-02, BR-14). Arama (UC-01 adım 3) ve rezervasyon aynı kuralı kullanır.
     *
     * @return array<string, string> alan => hata
     */
    public function dateErrors(DateRange $range): array
    {
        $errors = [];
        $today = $this->clock->today();
        if ($range->nights() < 1) {
            $errors['cikis'] = 'Çıkış tarihi giriş tarihinden sonra olmalıdır.';
        } elseif ($range->nights() > $this->settings->maxNights) {
            $errors['cikis'] = sprintf('Konaklama en çok %d gece olabilir.', $this->settings->maxNights);
        }
        if ($range->checkIn < $today) {
            $errors['giris'] = 'Giriş tarihi bugünden önce olamaz.';
        } elseif ($range->checkIn > $today->modify('+' . $this->settings->maxDaysAhead . ' days')) {
            $errors['giris'] = sprintf('Giriş tarihi en çok %d gün sonrası olabilir.', $this->settings->maxDaysAhead);
        }

        return $errors;
    }

    /**
     * Bölüm 4.4'ün doğrulama adımları: tarih, ilan/birim aktifliği, kapasite (BR-03),
     * ilan sahibinin kendi ilanına rezervasyon yapamaması (BR-19).
     */
    public function validate(Property $property, BookableUnit $unit, DateRange $range, int $guests, ?int $customerId = null): void
    {
        $errors = $this->dateErrors($range);
        if ($guests < 1) {
            $errors['kisi'] = 'Kişi sayısı en az 1 olmalıdır.';
        } elseif ($guests > $unit->capacity) {
            $errors['kisi'] = sprintf('Kişi sayısı bu odanın kapasitesini (%d kişi) aşıyor.', $unit->capacity);
        }
        if (!$property->isBookable() || $unit->status !== UnitStatus::Aktif) {
            $errors['ilan'] = 'Bu ilan veya oda şu anda rezervasyona kapalı.';
        }
        if ($customerId !== null && $customerId === $property->ownerId) {
            $errors['ilan'] = 'Kendi ilanınız için rezervasyon yapamazsınız.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    /**
     * Aralıkla kesişen ve şu an engelleyen rezervasyonlar.
     *
     * @return list<Reservation>
     */
    public function conflictingReservations(int $unitId, DateRange $range): array
    {
        $now = $this->clock->now();

        return array_values(array_filter(
            $this->reservations->findOverlapping($unitId, $range),
            fn (Reservation $r): bool => $this->isBlocking($r, $now) && $r->range()->overlaps($range)
        ));
    }

    /**
     * İlan sahibinin kapattığı geceler (BR-22).
     *
     * @return list<string> "Y-m-d"
     */
    public function closedNights(int $unitId, DateRange $range): array
    {
        $closed = [];
        foreach ($this->calendar->findForUnit($unitId, $range->checkIn, $range->checkOut) as $day => $entry) {
            if ($entry->status === CalendarStatus::Kapali) {
                $closed[] = $day;
            }
        }

        return $closed;
    }

    /** Ön bilgi (arama, takvim): kayıt oluşturmaz, kilit almaz. Kesin karar assertAvailable'dadır. */
    public function isAvailable(int $unitId, DateRange $range): bool
    {
        return $range->nights() >= 1
            && $this->conflictingReservations($unitId, $range) === []
            && $this->closedNights($unitId, $range) === [];
    }

    /**
     * Kesin karar (FR-11): işlem içinde birim kilitlenir, süresi dolmuş bekletmeler temizlenir
     * ve kural yeniden uygulanır. Çakışma varsa en çok 3 alternatif aralıkla ConflictException atılır.
     */
    public function assertAvailable(int $unitId, DateRange $range): void
    {
        $this->reservations->lockUnit($unitId);
        $this->expireStaleHolds($unitId);

        if ($this->conflictingReservations($unitId, $range) !== [] || $this->closedNights($unitId, $range) !== []) {
            $this->logger->info('cakisma_reddi', ['birim' => $unitId, 'giris' => $range->checkIn->format('Y-m-d'), 'cikis' => $range->checkOut->format('Y-m-d')]);
            throw new ConflictException('Seçtiğiniz tarihler artık müsait değil.', $this->suggestAlternatives($unitId, $range));
        }
    }

    /**
     * SRS 4.7 reserveNights(): rezervasyonun tutacağı geceler (giriş günü dahil, çıkış günü hariç).
     * Geceler rezervasyonla aynı atomik işlemde yazılır (createWithNights); serbest bırakma ise
     * engellemeyen duruma her geçişte ReservationStateMachine tarafından yapılır (SRS 4.3: tek yer).
     *
     * @return list<DateTimeImmutable>
     */
    public function reserveNights(DateRange $range): array
    {
        if ($range->nights() < 1) {
            throw ValidationException::single('cikis', 'Çıkış tarihi giriş tarihinden sonra olmalıdır.');
        }

        return $range->nightDates();
    }

    /**
     * Lazy expiry (FR-34, CS-05): süresi dolmuş Beklemede kayıtları Süresi Doldu yapar ve gecelerini
     * serbest bırakır. Rezervasyon ve zamanlanmış görev aynı kodu kullanır.
     */
    public function expireStaleHolds(?int $unitId = null): int
    {
        $count = 0;
        foreach ($this->reservations->findExpiredHolds($this->clock->now(), $unitId) as $reservation) {
            $this->expireHold($reservation);
            $count++;
        }

        return $count;
    }

    public function expireHold(Reservation $reservation): Reservation
    {
        $id = (int) $reservation->id;
        $paid = $this->payments->paidAmount($id);
        $updated = $this->stateMachine->transition(
            $reservation,
            ReservationStatus::SuresiDoldu,
            null,
            'Bekletme süresi doldu',
            ['refund_amount' => $paid]
        );
        if ($paid > 0) {
            $this->payments->refund($id, $paid); // BR-28: süre dolumunda ödenen tutarın tamamı iade
        }
        $message = sprintf('%s numaralı rezervasyonun bekletme süresi doldu; geceler serbest bırakıldı.', $reservation->number);
        if ($paid > 0) {
            $message .= ' Ödenen ' . Money::format($paid) . ' iade edildi (simüle).';
        }
        $this->notifications->notify($reservation->customerId, 'sure_doldu', $message);

        return $updated;
    }

    /**
     * FR-36: aynı birim için, aynı gece sayısında, istenen tarihe en yakın en çok N müsait aralık.
     *
     * @return list<DateRange>
     */
    public function suggestAlternatives(int $unitId, DateRange $range, ?int $limit = null): array
    {
        $limit ??= $this->settings->alternativeCount;
        $nights = $range->nights();
        if ($nights < 1) {
            return [];
        }
        $today = $this->clock->today();
        $lastCheckIn = $today->modify('+' . $this->settings->maxDaysAhead . ' days');
        $found = [];
        for ($distance = 1; $distance <= 60 && count($found) < $limit; $distance++) {
            foreach ([$distance, -$distance] as $shift) {
                $candidate = $range->shift($shift);
                if ($candidate->checkIn < $today || $candidate->checkIn > $lastCheckIn) {
                    continue;
                }
                if ($this->isAvailable($unitId, $candidate)) {
                    $found[] = $candidate;
                    if (count($found) >= $limit) {
                        break;
                    }
                }
            }
        }
        usort($found, fn (DateRange $a, DateRange $b): int => $a->checkIn <=> $b->checkIn);

        return $found;
    }

    /**
     * FR-10: takvimde her gecenin durumu. Rezervasyon kararıyla aynı kural kullanılır (T-08).
     *
     * @return array<string, string> "Y-m-d" => musait | dolu | kapali
     */
    public function nightStatuses(int $unitId, DateTimeImmutable $from, DateTimeImmutable $toExclusive): array
    {
        $range = new DateRange($from, $toExclusive);
        $blocking = $this->conflictingReservations($unitId, $range);
        $closed = array_flip($this->closedNights($unitId, $range));
        $statuses = [];
        foreach ($range->nightDates() as $night) {
            $day = $night->format('Y-m-d');
            $status = isset($closed[$day]) ? 'kapali' : 'musait';
            foreach ($blocking as $reservation) {
                if ($reservation->range()->containsNight($night)) {
                    $status = 'dolu';
                    break;
                }
            }
            $statuses[$day] = $status;
        }

        return $statuses;
    }
}
