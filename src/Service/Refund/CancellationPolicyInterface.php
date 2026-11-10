<?php

declare(strict_types=1);

namespace App\Service\Refund;

use App\Model\CancellationPolicy;

/**
 * Strategy deseni: her iptal politikası iade oranını kendisi hesaplar (SRS 4.11, BR-17).
 * Yeni bir politika eklemek yeni bir sınıf yazmaktır; RefundService değişmez.
 */
interface CancellationPolicyInterface
{
    public function policy(): CancellationPolicy;

    /** Girişe kalan gün (en az 1) için iade oranı (0-100). Giriş günü RefundService'te reddedilir. */
    public function refundPercent(int $daysLeft): int;

    /** Rezervasyondan önce kullanıcıya gösterilen koşul metni (BR-06). */
    public function description(): string;
}
