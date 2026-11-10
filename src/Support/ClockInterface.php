<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * Sunucu saati (NFR-17). Testlerde sabit saat (FrozenClock) verilerek
 * süre dolumu ve "bugün" kararları tekrarlanabilir biçimde sınanır.
 */
interface ClockInterface
{
    public function now(): DateTimeImmutable;

    /** Bugünün tarihi (saat 00:00, Europe/Istanbul). */
    public function today(): DateTimeImmutable;
}
