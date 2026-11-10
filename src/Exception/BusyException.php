<?php

declare(strict_types=1);

namespace App\Exception;

/** Kilit alınamadı (2 yeniden denemeden sonra). Hiçbir kayıt oluşmaz (UC-02 A7). */
class BusyException extends AppException
{
    public function __construct(string $message = 'Şu anda yoğunluk var, lütfen birkaç saniye sonra tekrar deneyin.')
    {
        parent::__construct($message);
    }
}
