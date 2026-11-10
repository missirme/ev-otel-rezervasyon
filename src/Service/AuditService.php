<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\AuditLog;
use App\Repository\AuditLogRepositoryInterface;
use App\Support\ClockInterface;
use App\Support\Paginator;

/** Denetim kaydı (FR-41, BR-10). */
final class AuditService
{
    public function __construct(
        private readonly AuditLogRepositoryInterface $logs,
        private readonly ClockInterface $clock,
    ) {
    }

    public function log(
        ?int $actorId,
        string $entityType,
        int $entityId,
        string $action,
        ?string $oldValue,
        ?string $newValue,
        ?string $reason = null,
    ): void {
        $this->logs->add(new AuditLog(null, $actorId, $entityType, $entityId, $action, $oldValue, $newValue, $reason, $this->clock->now()));
    }

    /** @return Paginator<AuditLog> */
    public function paginate(int $page, int $perPage, string $entityType = ''): Paginator
    {
        $logs = $this->logs->all();
        if ($entityType !== '') {
            $logs = array_values(array_filter($logs, fn (AuditLog $l): bool => $l->entityType === $entityType));
        }

        return new Paginator($logs, $page, $perPage);
    }
}
