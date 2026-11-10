<?php

declare(strict_types=1);

namespace App\Service\Refund;

use App\Model\CancellationPolicy;

/** Rezervasyonda saklanan politika değerine (anlık görüntü) karşılık gelen stratejiyi verir. */
final class CancellationPolicyFactory
{
    public function for(CancellationPolicy $policy): CancellationPolicyInterface
    {
        return match ($policy) {
            CancellationPolicy::Esnek => new FlexiblePolicy(),
            CancellationPolicy::Orta => new ModeratePolicy(),
            CancellationPolicy::Kati => new StrictPolicy(),
        };
    }
}
