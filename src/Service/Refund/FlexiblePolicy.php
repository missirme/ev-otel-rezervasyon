<?php

declare(strict_types=1);

namespace App\Service\Refund;

use App\Model\CancellationPolicy;

/** Esnek: girişe en az 1 gün kala %100 iade. */
final class FlexiblePolicy implements CancellationPolicyInterface
{
    public function policy(): CancellationPolicy
    {
        return CancellationPolicy::Esnek;
    }

    public function refundPercent(int $daysLeft): int
    {
        return $daysLeft >= 1 ? 100 : 0;
    }

    public function description(): string
    {
        return 'Esnek: Girişe en az 1 gün kala iptalde %100 iade. Giriş günü ve sonrasında iptal edilemez.';
    }
}
