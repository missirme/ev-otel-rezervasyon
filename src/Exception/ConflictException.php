<?php

declare(strict_types=1);

namespace App\Exception;

use App\Support\DateRange;

/**
 * Tarih çakışması (FR-36). Mesaj başka kullanıcının bilgisini içermez (NFR-04);
 * yalnızca en çok 3 alternatif tarih aralığı taşır.
 */
class ConflictException extends AppException
{
    /** @param list<DateRange> $alternatives */
    public function __construct(string $message = 'Seçtiğiniz tarihler artık müsait değil.', private readonly array $alternatives = [])
    {
        parent::__construct($message);
    }

    /** @return list<DateRange> */
    public function alternatives(): array
    {
        return $this->alternatives;
    }
}
