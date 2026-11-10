<?php

declare(strict_types=1);

namespace App\Repository\Json;

use App\Model\Complaint;
use App\Repository\ComplaintRepositoryInterface;

final class JsonComplaintRepository implements ComplaintRepositoryInterface
{
    private const TABLE = 'complaints';

    public function __construct(private readonly JsonDatabase $db)
    {
    }

    public function findById(int $id): ?Complaint
    {
        $row = $this->db->first(self::TABLE, fn (array $r): bool => $r['id'] === $id);

        return $row === null ? null : Complaint::fromArray($row);
    }

    public function all(): array
    {
        $list = array_map(Complaint::fromArray(...), $this->db->select(self::TABLE));
        usort($list, fn (Complaint $a, Complaint $b): int => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);

        return $list;
    }

    public function save(Complaint $complaint): Complaint
    {
        if ($complaint->id === null) {
            return Complaint::fromArray($this->db->insert(self::TABLE, $complaint->toArray()));
        }
        $id = $complaint->id;
        $this->db->update(self::TABLE, fn (array $r): bool => $r['id'] === $id, $complaint->toArray());

        return $complaint;
    }
}
