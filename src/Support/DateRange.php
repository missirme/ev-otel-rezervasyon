<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Konaklama aralığı: yarı açık [giriş, çıkış). Giriş günü dahil, çıkış günü hariç.
 * Çakışma kuralının tek karşılaştırma fonksiyonu burada (BR-11, risk R2).
 */
final class DateRange
{
    public readonly DateTimeImmutable $checkIn;
    public readonly DateTimeImmutable $checkOut;

    public function __construct(DateTimeImmutable $checkIn, DateTimeImmutable $checkOut)
    {
        $this->checkIn = $checkIn->setTime(0, 0);
        $this->checkOut = $checkOut->setTime(0, 0);
    }

    /** "2026-11-10" biçimindeki iki tarihten aralık üretir; biçim hatalıysa istisna atar. */
    public static function fromStrings(string $checkIn, string $checkOut, ?DateTimeZone $tz = null): self
    {
        return new self(self::parseDate($checkIn, $tz), self::parseDate($checkOut, $tz));
    }

    public static function parseDate(string $value, ?DateTimeZone $tz = null): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), $tz);
        if ($date === false || $date->format('Y-m-d') !== trim($value)) {
            throw new InvalidArgumentException('Geçersiz tarih: ' . $value);
        }

        return $date;
    }

    /** Gece sayısı = çıkış - giriş (BR-02). Geçersiz aralıkta 0 veya negatif olabilir. */
    public function nights(): int
    {
        $days = (int) $this->checkIn->diff($this->checkOut)->format('%r%a');

        return $days;
    }

    /** BR-11: A.giriş < B.çıkış ve B.giriş < A.çıkış ise çakışır. Aynı gün çıkış-giriş çakışmaz (BR-13). */
    public function overlaps(DateRange $other): bool
    {
        return $this->checkIn < $other->checkOut && $other->checkIn < $this->checkOut;
    }

    public function containsNight(DateTimeImmutable $night): bool
    {
        $n = $night->setTime(0, 0);

        return $n >= $this->checkIn && $n < $this->checkOut;
    }

    /**
     * Aralıktaki geceler (giriş günü ... çıkıştan önceki gün).
     *
     * @return list<DateTimeImmutable>
     */
    public function nightDates(): array
    {
        $dates = [];
        for ($d = $this->checkIn; $d < $this->checkOut; $d = $d->modify('+1 day')) {
            $dates[] = $d;
        }

        return $dates;
    }

    /** İki aralığın ortak gece sayısı (rapor için). */
    public function overlapNights(DateRange $other): int
    {
        $start = max($this->checkIn, $other->checkIn);
        $end = min($this->checkOut, $other->checkOut);
        if ($start >= $end) {
            return 0;
        }

        return (int) $start->diff($end)->format('%a');
    }

    public function shift(int $days): self
    {
        $modifier = ($days >= 0 ? '+' : '') . $days . ' days';

        return new self($this->checkIn->modify($modifier), $this->checkOut->modify($modifier));
    }

    public function equals(DateRange $other): bool
    {
        return $this->checkIn == $other->checkIn && $this->checkOut == $other->checkOut;
    }

    public function __toString(): string
    {
        return $this->checkIn->format('d.m.Y') . ' – ' . $this->checkOut->format('d.m.Y');
    }
}
