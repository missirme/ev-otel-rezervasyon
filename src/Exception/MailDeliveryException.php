<?php

declare(strict_types=1);

namespace App\Exception;

/** E-posta gönderilemedi (ör. simüle posta kutusu klasörüne yazılamadı). Kullanıcıya "gönderildi" denmez. */
final class MailDeliveryException extends AppException
{
}
