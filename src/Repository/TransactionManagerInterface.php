<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Atomik işlem sözleşmesi (FR-33). Aşama 2'de JSON dosya kilidi,
 * Aşama 3'te PDO transaction ile uygulanır; servisler farkı bilmez.
 */
interface TransactionManagerInterface
{
    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function transactional(callable $fn): mixed;
}
