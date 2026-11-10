<?php

declare(strict_types=1);

namespace App\Service\Dto;

use DateTimeImmutable;

/** Simüle posta kutusundaki bir ileti (geliştirme: /posta-kutusu). */
final class MailMessage
{
    public function __construct(
        public readonly string $id,
        public readonly string $to,
        public readonly string $subject,
        public readonly string $body,
        public readonly ?DateTimeImmutable $sentAt,
    ) {
    }
}
