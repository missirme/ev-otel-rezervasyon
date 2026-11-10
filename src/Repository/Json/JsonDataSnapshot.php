<?php

declare(strict_types=1);

namespace App\Repository\Json;

use App\Exception\PersistenceException;
use App\Repository\DataSnapshotInterface;

/** JSON deposunun yedeği: tüm tablolar tek dışlayıcı kilit altında okunur / yazılır. */
final class JsonDataSnapshot implements DataSnapshotInterface
{
    public function __construct(private readonly JsonDatabase $db)
    {
    }

    public function exportTables(): array
    {
        return $this->db->transaction(function (): array {
            $tables = [];
            foreach (array_keys(JsonSchema::tables()) as $table) {
                $tables[$table] = $this->db->select($table);
            }

            return $tables;
        });
    }

    public function importTables(array $tables): void
    {
        $unknown = array_diff(array_keys($tables), array_keys(JsonSchema::tables()));
        if ($unknown !== []) {
            throw new PersistenceException('Yedekte bilinmeyen tablo var: ' . implode(', ', $unknown));
        }
        $this->db->transaction(function () use ($tables): void {
            $this->db->truncateAll();
            foreach ($tables as $table => $rows) {
                foreach ($rows as $row) {
                    $this->db->insert($table, $row);
                }
            }
        });
    }
}
