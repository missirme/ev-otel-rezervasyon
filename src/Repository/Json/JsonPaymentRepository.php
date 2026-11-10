<?php

declare(strict_types=1);

namespace App\Repository\Json;

use App\Model\Payment;
use App\Repository\PaymentRepositoryInterface;

final class JsonPaymentRepository implements PaymentRepositoryInterface
{
    private const TABLE = 'payments';

    public function __construct(private readonly JsonDatabase $db)
    {
    }

    public function findByReservation(int $reservationId): array
    {
        return array_map(
            Payment::fromArray(...),
            $this->db->select(self::TABLE, fn (array $r): bool => $r['reservation_id'] === $reservationId)
        );
    }

    public function all(): array
    {
        return array_map(Payment::fromArray(...), $this->db->select(self::TABLE));
    }

    public function save(Payment $payment): Payment
    {
        if ($payment->id === null) {
            return Payment::fromArray($this->db->insert(self::TABLE, $payment->toArray()));
        }
        $id = $payment->id;
        $this->db->update(self::TABLE, fn (array $r): bool => $r['id'] === $id, $payment->toArray());

        return $payment;
    }
}
