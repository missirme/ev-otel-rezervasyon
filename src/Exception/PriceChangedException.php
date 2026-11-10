<?php

declare(strict_types=1);

namespace App\Exception;

use App\Service\Pricing\PriceQuote;

/** Onay ekranından sonra fiyat değişti: yeni döküm gösterilip yeniden onay alınır (BR-16, UC-02 A5). */
class PriceChangedException extends AppException
{
    public function __construct(public readonly PriceQuote $newQuote)
    {
        parent::__construct('Fiyat siz onay ekranındayken değişti. Lütfen yeni fiyat dökümünü kontrol edip tekrar onaylayın.');
    }
}
