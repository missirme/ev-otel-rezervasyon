<?php

declare(strict_types=1);

namespace App\Service\Dto;

use App\Model\BookableUnit;
use App\Model\Property;
use App\Model\User;
use App\Service\Pricing\PriceQuote;
use App\Support\DateRange;

/** UC-02 adım 2: ön kontrol ve fiyat dökümü. Bu aşamada geceler tutulmaz. */
final class ReservationPreview
{
    /** @param list<DateRange> $alternatives */
    public function __construct(
        public readonly Property $property,
        public readonly BookableUnit $unit,
        public readonly ?User $host,
        public readonly DateRange $range,
        public readonly int $guests,
        public readonly PriceQuote $quote,
        public readonly bool $available,
        public readonly array $alternatives,
        public readonly string $policyText,
    ) {
    }
}
