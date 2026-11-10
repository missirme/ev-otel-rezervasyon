<?php

declare(strict_types=1);

namespace App\Service\Dto;

use App\Model\PropertyType;
use App\Model\RoomType;

/** Arama ve filtre ölçütleri (FR-06, FR-07, FR-08). Tarihler formdaki metin hâliyle taşınır. */
final class SearchCriteria
{
    /**
     * @param list<RoomType> $roomTypes
     * @param list<int> $amenityIds
     */
    public function __construct(
        public readonly string $city = '',
        public readonly string $checkIn = '',
        public readonly string $checkOut = '',
        public readonly int $guests = 1,
        public readonly ?PropertyType $type = null,
        public readonly ?int $minPrice = null,   // gecelik, kuruş
        public readonly ?int $maxPrice = null,   // gecelik, kuruş
        public readonly ?float $minRating = null,
        public readonly array $roomTypes = [],
        public readonly array $amenityIds = [],
        public readonly string $sort = 'fiyat_artan',
        public readonly int $page = 1,
        public readonly ?int $viewerId = null,
    ) {
    }

    public function hasDates(): bool
    {
        return $this->checkIn !== '' || $this->checkOut !== '';
    }
}
