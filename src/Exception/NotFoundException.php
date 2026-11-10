<?php

declare(strict_types=1);

namespace App\Exception;

class NotFoundException extends AppException
{
    public function __construct(string $message = 'Aradığınız kayıt bulunamadı.')
    {
        parent::__construct($message);
    }
}
