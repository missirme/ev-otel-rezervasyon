<?php

declare(strict_types=1);

namespace App\Service\Dto;

use App\Model\Reservation;

/** $duplicate: aynı idempotency anahtarıyla ikinci gönderim; yeni kayıt oluşmadı (FR-35). */
final class CreateReservationResult
{
    public function __construct(public readonly Reservation $reservation, public readonly bool $duplicate)
    {
    }
}
