<?php

declare(strict_types=1);

namespace App\Service\Dto;

use App\Support\DateRange;

/** Rezervasyon oluşturma isteği (UC-02 adım 3). Controller formdan doldurur, servis $_POST okumaz. */
final class CreateReservationRequest
{
    public function __construct(
        public readonly int $customerId,
        public readonly int $unitId,
        public readonly DateRange $range,
        public readonly int $guests,
        public readonly string $idempotencyKey,
        public readonly int $expectedTotal, // kullanıcının onay ekranında gördüğü toplam (kuruş)
        public readonly string $contactName,
        public readonly string $contactPhone,
        public readonly ?string $note,
    ) {
    }
}
