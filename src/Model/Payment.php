<?php

declare(strict_types=1);

namespace App\Model;

use App\Support\Row;
use DateTimeImmutable;

/** Simüle ödeme kaydı (FR-37). Kart numarası gibi hassas veriler hiç tutulmaz. */
final class Payment
{
    public function __construct(
        public ?int $id,
        public int $reservationId,
        public string $providerRef,
        public int $amount,        // kuruş
        public int $refundAmount,  // kuruş
        public string $currency,
        public PaymentStatus $status,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /** Tahsil edilmiş (iade sonrası değişmeyen) tutar sayılır mı? */
    public function isCaptured(): bool
    {
        return $this->status->isCaptured();
    }

    /** @param array<string, mixed> $r */
    public static function fromArray(array $r): self
    {
        return new self(
            Row::int($r, 'id'),
            Row::int($r, 'reservation_id'),
            Row::string($r, 'provider_ref'),
            Row::decimal($r, 'amount'),
            Row::decimal($r, 'refund_amount'),
            Row::string($r, 'currency'),
            PaymentStatus::from(Row::string($r, 'status')),
            Row::dateTime($r, 'created_at'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservationId,
            'provider_ref' => $this->providerRef,
            'amount' => Row::fmtDecimal($this->amount),
            'refund_amount' => Row::fmtDecimal($this->refundAmount),
            'currency' => $this->currency,
            'status' => $this->status->value,
            'created_at' => Row::fmtDateTime($this->createdAt),
        ];
    }
}
