<?php

declare(strict_types=1);

namespace App\Model;

use App\Support\Row;
use DateTimeImmutable;

/** Denetim kaydı (FR-41, BR-10): kim, ne zaman, eski/yeni değer, neden. Sistem işlemlerinde actor boş. */
final class AuditLog
{
    public function __construct(
        public ?int $id,
        public ?int $actorId,
        public string $entityType,
        public int $entityId,
        public string $action,
        public ?string $oldValue,
        public ?string $newValue,
        public ?string $reason,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromArray(array $r): self
    {
        return new self(
            Row::int($r, 'id'),
            Row::nullableInt($r, 'actor_id'),
            Row::string($r, 'entity_type'),
            Row::int($r, 'entity_id'),
            Row::string($r, 'action'),
            Row::nullableString($r, 'old_value'),
            Row::nullableString($r, 'new_value'),
            Row::nullableString($r, 'reason'),
            Row::dateTime($r, 'created_at'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'actor_id' => $this->actorId,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'action' => $this->action,
            'old_value' => $this->oldValue,
            'new_value' => $this->newValue,
            'reason' => $this->reason,
            'created_at' => Row::fmtDateTime($this->createdAt),
        ];
    }
}
