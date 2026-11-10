<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Bonus: sayfalama. Liste dilimini ve sayfa bilgilerini taşır.
 *
 * @template T
 */
final class Paginator
{
    /** @var list<T> */
    public readonly array $items;
    public readonly int $total;
    public readonly int $page;
    public readonly int $perPage;
    public readonly int $pages;

    /** @param list<T> $all */
    public function __construct(array $all, int $page, int $perPage)
    {
        $this->total = count($all);
        $this->perPage = max(1, $perPage);
        $this->pages = max(1, (int) ceil($this->total / $this->perPage));
        $this->page = min(max(1, $page), $this->pages);
        $this->items = array_slice($all, ($this->page - 1) * $this->perPage, $this->perPage);
    }

    public function hasPages(): bool
    {
        return $this->pages > 1;
    }
}
