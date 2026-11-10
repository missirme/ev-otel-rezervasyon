<?php

declare(strict_types=1);

namespace App\Service\Refund;

use App\Model\CancellationPolicy;

/** Katı: girişe en az 14 gün kala %50 iade, 1-13 gün kala iade yok. */
final class StrictPolicy implements CancellationPolicyInterface
{
    public function policy(): CancellationPolicy
    {
        return CancellationPolicy::Kati;
    }

    public function refundPercent(int $daysLeft): int
    {
        return $daysLeft >= 14 ? 50 : 0;
    }

    public function description(): string
    {
        return 'Katı: Girişe en az 14 gün kala %50 iade, 1–13 gün kala iade yok. Giriş günü ve sonrasında iptal edilemez.';
    }
}
