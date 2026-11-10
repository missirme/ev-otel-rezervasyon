<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\CancellationPolicy;
use App\Model\Reservation;
use App\Model\ReservationStatus;
use App\Service\Refund\CancellationPolicyFactory;
use App\Service\Refund\CancellationPolicyInterface;
use App\Service\Refund\RefundQuote;
use App\Support\Dates;
use App\Support\Money;
use DateTimeImmutable;

/**
 * İptal politikası ve iade hesabı (ÇP-3, SRS 4.11, BR-17, BR-27 - BR-29).
 * Hesap, rezervasyonla saklanan politika anlık görüntüsüne göre yapılır.
 */
final class RefundService
{
    public function __construct(private readonly CancellationPolicyFactory $policies = new CancellationPolicyFactory())
    {
    }

    public function policyFor(CancellationPolicy $policy): CancellationPolicyInterface
    {
        return $this->policies->for($policy);
    }

    /** Müşteri iptali önizlemesi. Ödenen tutar $paid (kuruş) üzerinden hesaplanır. */
    public function quoteCustomerCancellation(Reservation $reservation, int $paid, DateTimeImmutable $today): RefundQuote
    {
        if (!in_array($reservation->status, [ReservationStatus::Beklemede, ReservationStatus::Onaylandi], true)) {
            return RefundQuote::denied('Bu rezervasyon "' . $reservation->status->label() . '" durumunda olduğu için iptal edilemez.');
        }
        $daysLeft = Dates::daysBetween($today, $reservation->checkIn);
        if ($daysLeft <= 0) {
            return RefundQuote::denied('Giriş günü ve sonrasında iptal yapılamaz.', $daysLeft);
        }
        if ($paid <= 0) {
            // BR-18: ödemesi alınmamış Beklemede kaydın iptalinde iade ve kesinti sıfırdır.
            return new RefundQuote(true, $daysLeft, 0, 0, 0, 0, 'Ödeme alınmadığı için iade veya kesinti yoktur.');
        }
        $percent = $this->policies->for($reservation->cancellationPolicy)->refundPercent($daysLeft);
        $refund = min($paid, Money::percent($paid, $percent * 100)); // iade ödenen tutarı aşamaz

        return new RefundQuote(
            true,
            $daysLeft,
            $percent,
            $paid,
            $refund,
            $paid - $refund,
            sprintf('Girişe %d gün kaldı; %s politikasına göre %%%d iade.', $daysLeft, $reservation->cancellationPolicy->label(), $percent)
        );
    }

    /** İlan sahibi reddi, yönetici iptali ve süre dolumunda ödenen tutarın tamamı iade edilir (BR-28). */
    public function fullRefund(int $paid): RefundQuote
    {
        return new RefundQuote(true, 0, 100, $paid, $paid, 0, 'Ödenen tutarın tamamı iade edilir.');
    }
}
