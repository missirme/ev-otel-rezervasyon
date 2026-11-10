<?php

declare(strict_types=1);

namespace App\Service\Refund;

use App\Model\CancellationPolicy;

/** Orta: girişe en az 5 gün kala %100, 1-4 gün kala %50 iade. */
final class ModeratePolicy implements CancellationPolicyInterface
{
    public function policy(): CancellationPolicy
    {
        return CancellationPolicy::Orta;
    }

    public function refundPercent(int $daysLeft): int
    {
        return $daysLeft >= 5 ? 100 : 50;
    }

    public function description(): string
    {
        return 'Orta: Girişe en az 5 gün kala %100, 1–4 gün kala %50 iade. Giriş günü ve sonrasında iptal edilemez.';
    }
}
