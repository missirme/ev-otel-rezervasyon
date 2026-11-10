<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Veri deposuna ulaşılamıyor (ör. veri klasörü okunamıyor).
 * Kullanıcıya 503 ile anlaşılır bir mesaj gösterilir; bağlantı ayrıntısı (parola vb.) mesajda yer almaz.
 */
class StorageUnavailableException extends PersistenceException
{
}
