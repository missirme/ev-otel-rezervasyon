<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Form/iş kuralı doğrulama hatası. Her hata ilgili alanın adıyla taşınır;
 * arayüz hatayı o alanın altında gösterir (NFR-06, SRS 5.1).
 */
class ValidationException extends AppException
{
    /** @param array<string, string> $errors alan => mesaj */
    public function __construct(private readonly array $errors, string $message = '')
    {
        parent::__construct($message !== '' ? $message : (string) (array_values($errors)[0] ?? 'Geçersiz bilgi.'));
    }

    public static function single(string $field, string $message): self
    {
        return new self([$field => $message]);
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
