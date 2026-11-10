<?php

declare(strict_types=1);

namespace App\Repository\Json;

use App\Exception\PersistenceException;
use App\Model\PaymentStatus;
use App\Model\Reservation;
use App\Model\ReservationCharge;
use App\Model\ReservationNight;
use App\Model\ReservationRevenue;
use App\Model\ReservationStatus;
use App\Repository\ReservationRepositoryInterface;
use App\Support\DateRange;
use App\Support\Decimal;
use App\Support\Row;
use DateTimeImmutable;

final class JsonReservationRepository implements ReservationRepositoryInterface
{
    private const TABLE = 'reservations';
    private const NIGHTS = 'reservation_nights';
    private const CHARGES = 'reservation_charges';
    private const ACTIVE = ['beklemede', 'onaylandi'];

    public function __construct(private readonly JsonDatabase $db)
    {
    }

    public function findById(int $id): ?Reservation
    {
        $row = $this->db->first(self::TABLE, fn (array $r): bool => $r['id'] === $id);

        return $row === null ? null : Reservation::fromArray($row);
    }

    public function findByNumber(string $number): ?Reservation
    {
        $row = $this->db->first(self::TABLE, fn (array $r): bool => $r['reservation_no'] === $number);

        return $row === null ? null : Reservation::fromArray($row);
    }

    public function findByIdempotencyKey(string $key): ?Reservation
    {
        $row = $this->db->first(self::TABLE, fn (array $r): bool => $r['idempotency_key'] === $key);

        return $row === null ? null : Reservation::fromArray($row);
    }

    public function numberExists(string $number): bool
    {
        return $this->findByNumber($number) !== null;
    }

    public function findOverlapping(int $unitId, DateRange $range): array
    {
        // Yarı açık aralık kesişimi metin karşılaştırmasıyla (Y-m-d sıralanabilir): giriş < çıkış' ve giriş' < çıkış
        $in = $range->checkIn->format('Y-m-d');
        $out = $range->checkOut->format('Y-m-d');

        return $this->map($this->db->select(
            self::TABLE,
            fn (array $r): bool => $r['unit_id'] === $unitId
                && in_array($r['status'], self::ACTIVE, true)
                && $r['check_in'] < $out
                && $in < $r['check_out']
        ));
    }

    public function findByCustomer(int $customerId): array
    {
        return $this->map($this->db->select(self::TABLE, fn (array $r): bool => $r['customer_id'] === $customerId));
    }

    public function findByUnits(array $unitIds): array
    {
        $set = array_flip($unitIds);

        return $this->map($this->db->select(self::TABLE, fn (array $r): bool => isset($set[$r['unit_id']])));
    }

    public function findExpiredHolds(DateTimeImmutable $now, ?int $unitId = null): array
    {
        $limit = $now->format('Y-m-d H:i:s');

        return $this->map($this->db->select(
            self::TABLE,
            fn (array $r): bool => $r['status'] === 'beklemede'
                && $r['expires_at'] !== null
                && $r['expires_at'] <= $limit
                && ($unitId === null || $r['unit_id'] === $unitId)
        ));
    }

    public function findConfirmedCheckedOutBy(DateTimeImmutable $date): array
    {
        $day = $date->format('Y-m-d');

        return $this->map($this->db->select(
            self::TABLE,
            fn (array $r): bool => $r['status'] === 'onaylandi' && $r['check_out'] <= $day
        ));
    }

    public function all(): array
    {
        return $this->map($this->db->select(self::TABLE));
    }

    public function createWithNights(Reservation $reservation, array $charges, array $nights): Reservation
    {
        // Tek işlem: herhangi bir adımda hata olursa (ör. UNIQUE(birim, gece) ihlali) hiçbiri yazılmaz.
        return $this->db->transaction(function () use ($reservation, $charges, $nights): Reservation {
            $data = $reservation->toArray();
            $data['id'] = null;
            $saved = Reservation::fromArray($this->db->insert(self::TABLE, $data));
            $id = $saved->id ?? throw new PersistenceException('Rezervasyon kimliği üretilemedi.');

            foreach ($charges as $charge) {
                $row = $charge->toArray();
                $row['id'] = null;
                $row['reservation_id'] = $id;
                $this->db->insert(self::CHARGES, $row);
            }
            foreach ($nights as $night) {
                $this->db->insert(self::NIGHTS, (new ReservationNight($id, $saved->unitId, $night))->toArray());
            }

            return $saved;
        });
    }

    public function updateStatusIf(int $id, ReservationStatus $expected, ReservationStatus $new, array $changes = []): bool
    {
        $values = ['status' => $new->value];
        foreach ($changes as $key => $value) {
            // Model türlerini depolama biçimine çevir (tarih-saat, enum, DECIMAL para alanları)
            $values[$key] = match (true) {
                $value instanceof DateTimeImmutable => Row::fmtDateTime($value),
                $value instanceof \BackedEnum => $value->value,
                in_array($key, ['refund_amount', 'total_price'], true) && is_int($value) => Decimal::fromInt($value),
                default => $value,
            };
        }
        $affected = $this->db->update(
            self::TABLE,
            fn (array $r): bool => $r['id'] === $id && $r['status'] === $expected->value,
            $values
        );

        return $affected === 1;
    }

    public function releaseNights(int $reservationId): int
    {
        return $this->db->delete(self::NIGHTS, fn (array $r): bool => $r['reservation_id'] === $reservationId);
    }

    public function nightsOf(int $reservationId): array
    {
        return array_map(
            ReservationNight::fromArray(...),
            $this->db->select(self::NIGHTS, fn (array $r): bool => $r['reservation_id'] === $reservationId)
        );
    }

    public function allNights(): array
    {
        return array_map(ReservationNight::fromArray(...), $this->db->select(self::NIGHTS));
    }

    public function chargesOf(int $reservationId): array
    {
        return array_map(
            ReservationCharge::fromArray(...),
            $this->db->select(self::CHARGES, fn (array $r): bool => $r['reservation_id'] === $reservationId)
        );
    }

    public function lockUnit(int $unitId): void
    {
        if (!$this->db->inTransaction()) {
            throw new PersistenceException('Birim kilidi yalnızca işlem içinde alınabilir.');
        }
        // JSON depoda işlem zaten dışlayıcı dosya kilidi altında; ayrıca bir şey gerekmez.
    }

    public function reportRows(?int $ownerId, DateRange $period): array
    {
        // reservations ⋈ bookable_units ⋈ properties ⟕ payments (Aşama 3'te aynı birleştirme SQL JOIN ile yazılacak)
        $ownerOfProperty = [];
        foreach ($this->db->select('properties') as $property) {
            $ownerOfProperty[(int) $property['id']] = (int) $property['owner_id'];
        }
        $ownerOfUnit = [];
        foreach ($this->db->select('bookable_units') as $unit) {
            $ownerOfUnit[(int) $unit['id']] = $ownerOfProperty[(int) $unit['property_id']] ?? null;
        }
        $captured = array_map(fn (PaymentStatus $s): string => $s->value, PaymentStatus::captured());
        $paid = [];
        $refunded = [];
        foreach ($this->db->select('payments', fn (array $r): bool => in_array($r['status'], $captured, true)) as $payment) {
            $reservationId = (int) $payment['reservation_id'];
            $paid[$reservationId] = ($paid[$reservationId] ?? 0) + Row::decimal($payment, 'amount');
            $refunded[$reservationId] = ($refunded[$reservationId] ?? 0) + Row::decimal($payment, 'refund_amount');
        }

        $from = $period->checkIn->format('Y-m-d');
        $to = $period->checkOut->format('Y-m-d');
        $rows = [];
        foreach ($this->db->select(self::TABLE, fn (array $r): bool => $r['check_in'] < $to && $r['check_out'] > $from) as $r) {
            $unitId = (int) $r['unit_id'];
            $owner = $ownerOfUnit[$unitId] ?? null;
            if ($owner === null || ($ownerId !== null && $owner !== $ownerId)) {
                continue;
            }
            $id = (int) $r['id'];
            $rows[] = new ReservationRevenue(
                $id,
                $unitId,
                ReservationStatus::from((string) $r['status']),
                Row::date($r, 'check_in'),
                Row::date($r, 'check_out'),
                $paid[$id] ?? 0,
                $refunded[$id] ?? 0,
            );
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<Reservation>
     */
    private function map(array $rows): array
    {
        return array_map(Reservation::fromArray(...), $rows);
    }
}
