<?php

declare(strict_types=1);

namespace App\Service\Pricing;

use App\Model\ChargeType;

/** Fiyat dökümünde gece dışındaki kalem: indirim, vergi veya ek ücret (kuruş; indirim eksi). */
final class PriceLine
{
    public function __construct(
        public readonly ChargeType $type,
        public readonly string $description,
        public readonly int $amount,
    ) {
    }
}
