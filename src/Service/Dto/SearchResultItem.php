<?php

declare(strict_types=1);

namespace App\Service\Dto;

use App\Model\BookableUnit;
use App\Model\Property;
use App\Service\Pricing\PriceQuote;

/** Arama sonucundaki ilan kartı. Tarih verildiyse en ucuz müsait birimin dökümü de taşınır (FR-44). */
final class SearchResultItem
{
    /** @param list<string> $amenityNames */
    public function __construct(
        public readonly Property $property,
        public readonly ?BookableUnit $cheapestUnit,
        public readonly int $availableUnits,
        public readonly ?PriceQuote $quote,
        public readonly int $nightly,
        public readonly ?float $rating,
        public readonly int $reviewCount,
        public readonly array $amenityNames,
        public readonly ?int $photoId,
        public readonly int $maxCapacity,
        public readonly bool $isFavorite = false,
    ) {
    }
}
