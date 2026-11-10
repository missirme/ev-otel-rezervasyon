<?php

declare(strict_types=1);

namespace App\Exception;

/** Rol veya sahiplik kuralı ihlali; sunucu tarafında reddedilir (FR-05, NFR-03). */
class ForbiddenException extends AppException
{
    public function __construct(string $message = 'Bu işlem için yetkiniz yok.')
    {
        parent::__construct($message);
    }
}
