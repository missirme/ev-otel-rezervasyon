<?php

declare(strict_types=1);

namespace App\Service\Pricing;

use App\Support\Dates;
use DateTimeImmutable;

/** Tek bir gecenin fiyat hesabı: temel fiyat, uygulanan yüzde çarpanları ve yuvarlanmış sonuç. */
final class NightPrice
{
    /** @var list<int> uygulanan yüzde farkları (baz puan) */
    public array $factors = [];

    /** @var list<string> dökümde gösterilecek açıklamalar */
    public array $labels = [];

    /** Yuvarlanmış gece fiyatı (kuruş) */
    public int $price = 0;

    public function __construct(
        public readonly DateTimeImmutable $date,
        public int $base = 0,
        public bool $isException = false,
    ) {
    }

    /** Hafta sonu geceleri Cuma ve Cumartesi geceleridir (BR-23). */
    public function isWeekend(): bool
    {
        return in_array((int) $this->date->format('N'), [5, 6], true);
    }

    public function description(): string
    {
        $text = $this->date->format('d.m.Y') . ' ' . Dates::dayName($this->date);

        return $this->labels === [] ? $text : $text . ' (' . implode(', ', $this->labels) . ')';
    }
}
