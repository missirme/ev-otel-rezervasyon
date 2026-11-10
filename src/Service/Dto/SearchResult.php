<?php

declare(strict_types=1);

namespace App\Service\Dto;

use App\Support\DateRange;
use App\Support\Paginator;

final class SearchResult
{
    /**
     * @param Paginator<SearchResultItem> $items
     * @param array<string, string> $errors
     */
    public function __construct(
        public readonly SearchCriteria $criteria,
        public readonly Paginator $items,
        public readonly array $errors,
        public readonly ?DateRange $range,
    ) {
    }
}
