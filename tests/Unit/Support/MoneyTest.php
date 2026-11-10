<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Decimal;
use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/** BR-25: kuruş bazında, yarıdan yukarı yuvarlama; float kullanılmaz. */
#[Group('BR-25')]
final class MoneyTest extends TestCase
{
    public function testRoundHalfUp(): void
    {
        self::assertSame(3, Money::roundDiv(5, 2));    // 2,5 -> 3
        self::assertSame(2, Money::roundDiv(9, 4));    // 2,25 -> 2
        self::assertSame(-3, Money::roundDiv(-5, 2));  // eksi tutar da sıfırdan uzağa
    }

    public function testFactorsAreRoundedOnceAtTheEnd(): void
    {
        // 1.000 TL x 1,30 x 1,20 = 1.560 TL (AC-23)
        self::assertSame(156000, Money::applyFactors(100000, [3000, 2000]));
        // 333,33 x 1,30 x 1,20 = 519,9948 -> 519,99 (tek seferde yuvarlama)
        self::assertSame(51999, Money::applyFactors(33333, [3000, 2000]));
    }

    public function testPercent(): void
    {
        self::assertSame(96200, Money::percent(962000, 1000));      // 9.620 x %10 = 962
        self::assertSame(476190, Money::percent(952380, 5000));     // 9.523,80 x %50 = 4.761,90
    }

    public function testDecimalConversion(): void
    {
        self::assertSame(156000, Decimal::toInt('1560.00'));
        self::assertSame(125050, Decimal::toInt('1250,5'));
        self::assertSame('9523.80', Decimal::fromInt(952380));
        self::assertSame('-962.00', Decimal::fromInt(-96200));
        self::assertSame('9.523,80 TL', Money::format(952380));
        self::assertSame('%12,5', Money::formatPercent(1250));
    }

    public function testInvalidDecimalIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Decimal::toInt('12.345');
    }
}
