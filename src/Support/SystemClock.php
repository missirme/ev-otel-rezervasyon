<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

final class SystemClock implements ClockInterface
{
    private DateTimeZone $timezone;

    public function __construct(string $timezone = 'Europe/Istanbul')
    {
        $this->timezone = new DateTimeZone($timezone);
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->timezone);
    }

    public function today(): DateTimeImmutable
    {
        return $this->now()->setTime(0, 0);
    }
}
