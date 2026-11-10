<?php

declare(strict_types=1);

namespace App\Model;

use App\Support\Row;
use DateTimeImmutable;

/** Rezervasyon kalemi: gecelik döküm, indirim, vergi ve ek ücret (fiyat anlık görüntüsü, BR-16). */
final class ReservationCharge
{
    public function __construct(
        public ?int $id,
        public int $reservationId,
        public ChargeType $type,
        public ?DateTimeImmutable $nightDate,
        public string $description,
        public int $amount, // kuruş; indirim eksi
    ) {
    }

    /** @param array<string, mixed> $r */
    public static function fromArray(array $r): self
    {
        return new self(
            Row::int($r, 'id'),
            Row::int($r, 'reservation_id'),
            ChargeType::from(Row::string($r, 'type')),
            Row::nullableDate($r, 'night_date'),
            Row::string($r, 'description'),
            Row::decimal($r, 'amount'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'reservation_id' => $this->reservationId,
            'type' => $this->type->value,
            'night_date' => Row::fmtDate($this->nightDate),
            'description' => $this->description,
            'amount' => Row::fmtDecimal($this->amount),
        ];
    }
}
