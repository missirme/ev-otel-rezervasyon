<?php

declare(strict_types=1);

namespace App\Model;

use App\Support\DateRange;
use App\Support\Row;
use DateTimeImmutable;

/**
 * Rezervasyon (SRS 7). Toplam fiyat ve iptal politikası rezervasyon anındaki
 * değerin anlık görüntüsüdür (BR-16, BR-29).
 */
final class Reservation
{
    public function __construct(
        public ?int $id,
        public string $number,
        public int $customerId,
        public int $unitId,
        public DateTimeImmutable $checkIn,
        public DateTimeImmutable $checkOut,
        public int $guestCount,
        public ReservationStatus $status,
        public ?DateTimeImmutable $expiresAt,
        public int $totalPrice, // kuruş
        public CancellationPolicy $cancellationPolicy,
        public string $idempotencyKey,
        public string $contactName,
        public string $contactPhone,
        public ?string $note,
        public ?string $cancelReason,
        public ?DateTimeImmutable $cancelledAt,
        public int $refundAmount, // kuruş
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    public function range(): DateRange
    {
        return new DateRange($this->checkIn, $this->checkOut);
    }

    public function nights(): int
    {
        return $this->range()->nights();
    }

    /** @param array<string, mixed> $r */
    public static function fromArray(array $r): self
    {
        return new self(
            Row::int($r, 'id'),
            Row::string($r, 'reservation_no'),
            Row::int($r, 'customer_id'),
            Row::int($r, 'unit_id'),
            Row::date($r, 'check_in'),
            Row::date($r, 'check_out'),
            Row::int($r, 'guest_count'),
            ReservationStatus::from(Row::string($r, 'status')),
            Row::nullableDateTime($r, 'expires_at'),
            Row::decimal($r, 'total_price'),
            CancellationPolicy::from(Row::string($r, 'cancellation_policy')),
            Row::string($r, 'idempotency_key'),
            Row::string($r, 'contact_name'),
            Row::string($r, 'contact_phone'),
            Row::nullableString($r, 'note'),
            Row::nullableString($r, 'cancel_reason'),
            Row::nullableDateTime($r, 'cancelled_at'),
            Row::decimal($r, 'refund_amount'),
            Row::dateTime($r, 'created_at'),
            Row::dateTime($r, 'updated_at'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'reservation_no' => $this->number,
            'customer_id' => $this->customerId,
            'unit_id' => $this->unitId,
            'check_in' => Row::fmtDate($this->checkIn),
            'check_out' => Row::fmtDate($this->checkOut),
            'guest_count' => $this->guestCount,
            'status' => $this->status->value,
            'expires_at' => Row::fmtDateTime($this->expiresAt),
            'total_price' => Row::fmtDecimal($this->totalPrice),
            'cancellation_policy' => $this->cancellationPolicy->value,
            'idempotency_key' => $this->idempotencyKey,
            'contact_name' => $this->contactName,
            'contact_phone' => $this->contactPhone,
            'note' => $this->note,
            'cancel_reason' => $this->cancelReason,
            'cancelled_at' => Row::fmtDateTime($this->cancelledAt),
            'refund_amount' => Row::fmtDecimal($this->refundAmount),
            'created_at' => Row::fmtDateTime($this->createdAt),
            'updated_at' => Row::fmtDateTime($this->updatedAt),
        ];
    }
}
