<?php

declare(strict_types=1);

namespace App\Support;

use OverflowException;

/**
 * Kuruş (int) ile para hesabı. Her yuvarlama "yarıdan yukarı"dır (BR-25).
 */
final class Money
{
    /** Bölmeyi kuruşa yarıdan yukarı (sıfırdan uzağa) yuvarlar. */
    public static function roundDiv(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new \InvalidArgumentException('Payda pozitif olmalı.');
        }
        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;
        if (2 * abs($remainder) >= $denominator) {
            $quotient += $numerator >= 0 ? 1 : -1;
        }

        return $quotient;
    }

    /** Tutarın yüzdesi; $basisPoints 1000 = %10. */
    public static function percent(int $kurus, int $basisPoints): int
    {
        return self::roundDiv($kurus * $basisPoints, 10000);
    }

    /**
     * Tutarı sırayla (1 + yüzde) çarpanlarıyla çarpar ve tek seferde yuvarlar.
     * Örnek: 1000 TL x (1 + %30) x (1 + %20) = 1560 TL (BR-23).
     *
     * @param list<int> $basisPointFactors
     */
    public static function applyFactors(int $kurus, array $basisPointFactors): int
    {
        $numerator = $kurus;
        $denominator = 1;
        foreach ($basisPointFactors as $bp) {
            $factor = 10000 + $bp;
            // Tam sayı taşmasını çarpmadan önce yakala (PHP taşmada sessizce float'a geçer)
            if ($factor < 0 || abs($numerator) > intdiv(PHP_INT_MAX, max(1, $factor)) || $denominator > intdiv(PHP_INT_MAX, 10000)) {
                throw new OverflowException('Fiyat çarpanı taştı.');
            }
            $numerator *= $factor;
            $denominator *= 10000;
        }

        return self::roundDiv($numerator, $denominator);
    }

    /** 156000 -> "1.560,00 TL" */
    public static function format(int $kurus): string
    {
        $sign = $kurus < 0 ? '-' : '';
        $abs = abs($kurus);
        $lira = number_format(intdiv($abs, 100), 0, ',', '.');

        return $sign . $lira . ',' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT) . ' TL';
    }

    /** 3000 baz puan -> "%30", 1250 -> "%12,5" */
    public static function formatPercent(int $basisPoints): string
    {
        $whole = intdiv(abs($basisPoints), 100);
        $fraction = abs($basisPoints) % 100;
        $text = (string) $whole;
        if ($fraction !== 0) {
            $text .= ',' . rtrim(str_pad((string) $fraction, 2, '0', STR_PAD_LEFT), '0');
        }

        return ($basisPoints < 0 ? '-' : '') . '%' . $text;
    }
}
