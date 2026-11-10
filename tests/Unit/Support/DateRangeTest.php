<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\DateRange;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * T-01 (AC-11): SRS 4.2 örnek tablosundaki 8 durum. Mevcut Onaylandı rezervasyon: 10–12 Kasım 2026.
 */
final class DateRangeTest extends TestCase
{
    /** @return array<string, array{string, string, bool|null}> */
    public static function srsExampleTable(): array
    {
        return [
            'arka arkaya: çıkış = giriş (12-14)' => ['2026-11-12', '2026-11-14', false],
            'yeni çıkış = mevcut giriş (8-10)' => ['2026-11-08', '2026-11-10', false],
            '10 Kasım gecesi ortak (9-11)' => ['2026-11-09', '2026-11-11', true],
            '11 Kasım gecesi ortak (11-13)' => ['2026-11-11', '2026-11-13', true],
            'aynı aralık (10-12)' => ['2026-11-10', '2026-11-12', true],
            'kapsayan aralık (9-13)' => ['2026-11-09', '2026-11-13', true],
            'içte kalan aralık (10-11)' => ['2026-11-10', '2026-11-11', true],
            'sıfır gece (12-12) geçersiz' => ['2026-11-12', '2026-11-12', null],
        ];
    }

    #[DataProvider('srsExampleTable')]
    #[Group('T-01')]
    #[Group('AC-11')]
    #[Group('BR-11')]
    public function testSrsOverlapTable(string $in, string $out, ?bool $expected): void
    {
        $existing = DateRange::fromStrings('2026-11-10', '2026-11-12');
        $request = DateRange::fromStrings($in, $out);

        if ($expected === null) {
            // Geçersiz: tarih doğrulamasında reddedilir, çakışma kontrolüne ulaşmaz (BR-02)
            self::assertSame(0, $request->nights());

            return;
        }
        self::assertSame($expected, $request->overlaps($existing));
        self::assertSame($expected, $existing->overlaps($request), 'Çakışma simetrik olmalı');
    }

    #[Group('BR-02')]
    #[Group('BR-11')]
    #[Group('BR-13')]
    public function testNightsAreHalfOpen(): void
    {
        $range = DateRange::fromStrings('2026-11-10', '2026-11-12');

        self::assertSame(2, $range->nights());
        self::assertSame(['2026-11-10', '2026-11-11'], array_map(fn ($d) => $d->format('Y-m-d'), $range->nightDates()));
        self::assertTrue($range->containsNight(DateRange::parseDate('2026-11-11')));
        self::assertFalse($range->containsNight(DateRange::parseDate('2026-11-12')), 'Çıkış günü gece sayılmaz');
    }

    #[Group('BR-30')]
    public function testOverlapNightsForReports(): void
    {
        $stay = DateRange::fromStrings('2026-10-30', '2026-11-03');
        $november = DateRange::fromStrings('2026-11-01', '2026-12-01');

        self::assertSame(2, $stay->overlapNights($november));
        self::assertSame(0, DateRange::fromStrings('2026-10-01', '2026-10-05')->overlapNights($november));
    }

    #[Group('BR-01')]
    public function testInvalidDateFormatIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DateRange::parseDate('2026-02-30');
    }

    #[Group('FR-36')]
    public function testShiftKeepsLength(): void
    {
        $shifted = DateRange::fromStrings('2026-11-10', '2026-11-13')->shift(-2);

        self::assertSame('2026-11-08', $shifted->checkIn->format('Y-m-d'));
        self::assertSame(3, $shifted->nights());
    }
}
