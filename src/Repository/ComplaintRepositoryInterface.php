<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Complaint;

interface ComplaintRepositoryInterface
{
    public function findById(int $id): ?Complaint;

    /**
     * En yeni önce.
     *
     * @return list<Complaint>
     */
    public function all(): array;

    public function save(Complaint $complaint): Complaint;
}
