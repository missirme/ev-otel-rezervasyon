<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\PersistenceException;
use App\Model\Payment;
use App\Model\PaymentStatus;
use App\Model\Reservation;
use App\Repository\PaymentRepositoryInterface;
use App\Support\ClockInterface;

/**
 * Simüle ödeme (FR-37). Gerçek para tahsilatı kapsam dışıdır (SRS 11.2);
 * kart bilgisi istenmez ve saklanmaz. İade de simüledir.
 */
final class PaymentService
{
    public function __construct(
        private readonly PaymentRepositoryInterface $payments,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Ödenen toplam (iade hesabının tabanı, SRS 4.11). */
    public function paidAmount(int $reservationId): int
    {
        $total = 0;
        foreach ($this->payments->findByReservation($reservationId) as $payment) {
            if ($payment->isCaptured()) {
                $total += $payment->amount;
            }
        }

        return $total;
    }

    public function refundedAmount(int $reservationId): int
    {
        $total = 0;
        foreach ($this->payments->findByReservation($reservationId) as $payment) {
            $total += $payment->refundAmount;
        }

        return $total;
    }

    public function hasCapturedPayment(int $reservationId): bool
    {
        return $this->paidAmount($reservationId) > 0;
    }

    /** Simüle ödeme denemesi; sonucu kullanıcının seçtiği test değeri belirler. */
    public function simulate(Reservation $reservation, bool $succeed): Payment
    {
        $id = $reservation->id ?? throw new PersistenceException('Kaydedilmemiş rezervasyon için ödeme alınamaz.');

        return $this->payments->save(new Payment(
            null,
            $id,
            'SIM-' . strtoupper(bin2hex(random_bytes(6))),
            $reservation->totalPrice,
            0,
            'TRY',
            $succeed ? PaymentStatus::Basarili : PaymentStatus::Basarisiz,
            $this->clock->now(),
        ));
    }

    /** Simüle iade: tahsil edilmiş ödemelere iade tutarını işler. */
    public function refund(int $reservationId, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }
        $remaining = $amount;
        foreach ($this->payments->findByReservation($reservationId) as $payment) {
            if (!$payment->isCaptured() || $remaining <= 0) {
                continue;
            }
            $refundable = $payment->amount - $payment->refundAmount;
            $part = min($refundable, $remaining);
            if ($part <= 0) {
                continue;
            }
            $payment->refundAmount += $part;
            $payment->status = $payment->refundAmount >= $payment->amount ? PaymentStatus::IadeEdildi : PaymentStatus::KismenIade;
            $this->payments->save($payment);
            $remaining -= $part;
        }
    }
}
