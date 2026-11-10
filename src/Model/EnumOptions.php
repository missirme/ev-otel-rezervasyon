<?php

declare(strict_types=1);

namespace App\Model;

/** Enum'lar için açılır liste seçenekleri (değer => etiket). */
trait EnumOptions
{
    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
