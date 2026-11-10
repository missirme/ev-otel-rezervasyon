<?php

declare(strict_types=1);

namespace App\Model;

use App\Support\Row;
use DateTimeImmutable;

/** Parola sıfırlama jetonu (FR-04). Jetonun kendisi değil SHA-256 özeti saklanır. */
final class PasswordResetToken
{
    public function __construct(
        public ?int $id,
        public int $userId,
        public string $tokenHash,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $usedAt,
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromArray(array $r): self
    {
        return new self(
            Row::int($r, 'id'),
            Row::int($r, 'user_id'),
            Row::string($r, 'token_hash'),
            Row::dateTime($r, 'expires_at'),
            Row::nullableDateTime($r, 'used_at'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'token_hash' => $this->tokenHash,
            'expires_at' => Row::fmtDateTime($this->expiresAt),
            'used_at' => Row::fmtDateTime($this->usedAt),
        ];
    }
}
