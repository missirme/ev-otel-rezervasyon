<?php

declare(strict_types=1);

namespace App\Model;

use App\Support\Row;
use DateTimeImmutable;

/** Uygulama içi bildirim (FR-26). */
final class Notification
{
    public function __construct(
        public ?int $id,
        public int $userId,
        public string $type,
        public string $message,
        public bool $isRead,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromArray(array $r): self
    {
        return new self(
            Row::int($r, 'id'),
            Row::int($r, 'user_id'),
            Row::string($r, 'type'),
            Row::string($r, 'message'),
            Row::bool($r, 'is_read'),
            Row::dateTime($r, 'created_at'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'type' => $this->type,
            'message' => $this->message,
            'is_read' => $this->isRead,
            'created_at' => Row::fmtDateTime($this->createdAt),
        ];
    }
}
