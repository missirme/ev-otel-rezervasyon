<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Reservation;
use App\Model\ReservationCharge;
use App\Model\ReservationNight;
use App\Model\ReservationRevenue;
use App\Model\ReservationStatus;
use App\Support\DateRange;
use DateTimeImmutable;

/**
 * Rezervasyon, gece ve kalem kayıtları (SRS 4.7, 5.2). İş kuralı içermez:
 * "engelleyen durum" kararı BookingConflictService'tedir.
 */
interface ReservationRepositoryInterface
{
    public function findById(int $id): ?Reservation;

    public function findByNumber(string $number): ?Reservation;

    public function findByIdempotencyKey(string $key): ?Reservation;

    public function numberExists(string $number): bool;

    /**
     * Aynı birimde aralıkla kesişen Beklemede/Onaylandı kayıtlar (süre kontrolü servistedir).
     *
     * @return list<Reservation>
     */
    public function findOverlapping(int $unitId, DateRange $range): array;

    /** @return list<Reservation> */
    public function findByCustomer(int $customerId): array;

    /**
     * @param list<int> $unitIds
     * @return list<Reservation>
     */
    public function findByUnits(array $unitIds): array;

    /**
     * expires_at zamanı geçmiş Beklemede kayıtlar.
     *
     * @return list<Reservation>
     */
    public function findExpiredHolds(DateTimeImmutable $now, ?int $unitId = null): array;

    /**
     * Çıkış tarihi verilen günde veya öncesinde olan Onaylandı kayıtlar (FR-40).
     *
     * @return list<Reservation>
     */
    public function findConfirmedCheckedOutBy(DateTimeImmutable $date): array;

    /** @return list<Reservation> */
    public function all(): array;

    /**
     * Atomik yazma sözleşmesi (FR-33): rezervasyon, kalemler ve geceler birlikte yazılır.
     * Herhangi biri başarısız olursa hiçbiri kalmaz (çağıran işlem geri alınır).
     *
     * @param list<ReservationCharge> $charges
     * @param list<DateTimeImmutable> $nights
     */
    public function createWithNights(Reservation $reservation, array $charges, array $nights): Reservation;

    /**
     * Koşullu güncelleme: yalnızca durum hâlâ $expected ise günceller.
     * SQL karşılığı: UPDATE ... WHERE id = ? AND status = ?; etkilenen satır 0 ise false döner.
     *
     * @param array<string, mixed> $changes
     */
    public function updateStatusIf(int $id, ReservationStatus $expected, ReservationStatus $new, array $changes = []): bool;

    /** Rezervasyonun gece kayıtlarını siler (geceler serbest kalır). */
    public function releaseNights(int $reservationId): int;

    /** @return list<ReservationNight> */
    public function nightsOf(int $reservationId): array;

    /** @return list<ReservationNight> */
    public function allNights(): array;

    /** @return list<ReservationCharge> */
    public function chargesOf(int $reservationId): array;

    /**
     * Birim satırını kilitler (Aşama 3: SELECT ... FOR UPDATE).
     * Aşama 2'de işlem zaten tek dışlayıcı dosya kilidi altında çalıştığından
     * ek iş gerekmez; çağrının işlem içinde yapıldığı denetlenir.
     */
    public function lockUnit(int $unitId): void;

    /**
     * Doluluk ve gelir raporunun veri kaynağı (FR-30, BR-30): dönemle ([giriş, çıkış) yarı açık) kesişen
     * rezervasyonlar ve her birinin tahsil edilmiş ödeme / iade toplamı.
     * SQL karşılığı: reservations ⋈ bookable_units ⋈ properties ⟕ payments, GROUP BY rezervasyon.
     * $ownerId verilirse yalnızca o ilan sahibinin ilanları (yönetici için null).
     *
     * @return list<ReservationRevenue>
     */
    public function reportRows(?int $ownerId, DateRange $period): array;
}
