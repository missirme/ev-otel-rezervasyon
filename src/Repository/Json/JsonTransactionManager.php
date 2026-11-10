<?php

declare(strict_types=1);

namespace App\Repository\Json;

use App\Repository\TransactionManagerInterface;

final class JsonTransactionManager implements TransactionManagerInterface
{
    public function __construct(private readonly JsonDatabase $db)
    {
    }

    public function transactional(callable $fn): mixed
    {
        return $this->db->transaction($fn);
    }
}
