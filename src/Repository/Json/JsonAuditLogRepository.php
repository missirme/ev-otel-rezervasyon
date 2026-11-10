<?php

declare(strict_types=1);

namespace App\Repository\Json;

use App\Model\AuditLog;
use App\Repository\AuditLogRepositoryInterface;

final class JsonAuditLogRepository implements AuditLogRepositoryInterface
{
    private const TABLE = 'audit_logs';

    public function __construct(private readonly JsonDatabase $db)
    {
    }

    public function add(AuditLog $log): AuditLog
    {
        return AuditLog::fromArray($this->db->insert(self::TABLE, $log->toArray()));
    }

    public function all(): array
    {
        $list = array_map(AuditLog::fromArray(...), $this->db->select(self::TABLE));
        usort($list, fn (AuditLog $a, AuditLog $b): int => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);

        return $list;
    }
}
