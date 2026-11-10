<?php

declare(strict_types=1);

namespace App\Exception;

/** Veri katmanı (JSON dosyası / veritabanı) yazma hatası. İşlem geri alınır (CS-12). */
class PersistenceException extends AppException
{
}
