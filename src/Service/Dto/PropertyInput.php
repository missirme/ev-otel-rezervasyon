<?php

declare(strict_types=1);

namespace App\Service\Dto;

use App\Model\BookingMode;
use App\Model\CancellationPolicy;
use App\Model\PropertyStatus;
use App\Model\PropertyType;

/** İlan oluşturma/düzenleme formu (FR-20, FR-21, FR-38). */
final class PropertyInput
{
    /** @param list<int> $amenityIds */
    public function __construct(
        public readonly string $name,
        public readonly PropertyType $type,
        public readonly string $description,
        public readonly string $address,
        public readonly string $city,
        public readonly BookingMode $bookingMode,
        public readonly CancellationPolicy $cancellationPolicy,
        public readonly PropertyStatus $status,
        public readonly array $amenityIds,
    ) {
    }
}
