<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Payment;

interface PaymentRepositoryInterface
{
    /** @return list<Payment> */
    public function findByReservation(int $reservationId): array;

    /** @return list<Payment> */
    public function all(): array;

    public function save(Payment $payment): Payment;
}
