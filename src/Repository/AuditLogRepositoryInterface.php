<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\AuditLog;

interface AuditLogRepositoryInterface
{
    public function add(AuditLog $log): AuditLog;

    /**
     * En yeni önce.
     *
     * @return list<AuditLog>
     */
    public function all(): array;
}
