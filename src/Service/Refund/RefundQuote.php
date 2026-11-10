<?php

declare(strict_types=1);

namespace App\Service\Refund;

/** İptal önizlemesi: kalan gün, oran, iade ve kesinti (FR-45). Tutarlar kuruş. */
final class RefundQuote
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $daysLeft,
        public readonly int $percent,
        public readonly int $paid,
        public readonly int $refund,
        public readonly int $deduction,
        public readonly string $message,
    ) {
    }

    public static function denied(string $message, int $daysLeft = 0): self
    {
        return new self(false, $daysLeft, 0, 0, 0, 0, $message);
    }
}
