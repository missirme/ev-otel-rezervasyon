<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/** Testler ve eşzamanlılık işçileri için durdurulmuş saat. */
final class FrozenClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(string|DateTimeImmutable $now, string $timezone = 'Europe/Istanbul')
    {
        $this->now = $now instanceof DateTimeImmutable
            ? $now
            : new DateTimeImmutable($now, new DateTimeZone($timezone));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function today(): DateTimeImmutable
    {
        return $this->now->setTime(0, 0);
    }

    public function set(string|DateTimeImmutable $now): void
    {
        $this->now = $now instanceof DateTimeImmutable ? $now : new DateTimeImmutable($now, $this->now->getTimezone());
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
