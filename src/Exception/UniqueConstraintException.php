<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Benzersizlik kısıtı ihlali. Örn. reservation_nights tablosunda (unit_id, night_date)
 * çifti ikinci kez yazılmak istenirse oluşur: çift rezervasyona karşı son savunma (SRS 4.5).
 */
class UniqueConstraintException extends PersistenceException
{
    /** @param list<string> $columns */
    public function __construct(public readonly string $table, public readonly array $columns)
    {
        parent::__construct(sprintf('Benzersizlik kısıtı ihlali: %s (%s)', $table, implode(', ', $columns)));
    }
}
