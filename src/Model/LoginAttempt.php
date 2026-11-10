<?php

declare(strict_types=1);

namespace App\Model;

use App\Support\Row;
use DateTimeImmutable;

/** Başarısız giriş sayacı (NFR-15): art arda 5 hatadan sonra 15 dakika kilit. */
final class LoginAttempt
{
    public function __construct(
        public string $email,
        public int $failedCount,
        public ?DateTimeImmutable $lastFailedAt,
        public ?DateTimeImmutable $lockedUntil,
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromArray(array $r): self
    {
        return new self(
            Row::string($r, 'email'),
            Row::int($r, 'failed_count'),
            Row::nullableDateTime($r, 'last_failed_at'),
            Row::nullableDateTime($r, 'locked_until'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'failed_count' => $this->failedCount,
            'last_failed_at' => Row::fmtDateTime($this->lastFailedAt),
            'locked_until' => Row::fmtDateTime($this->lockedUntil),
        ];
    }
}
