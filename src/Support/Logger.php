<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Basit dosya günlüğü (NFR-10, NFR-18). Parola, kart verisi veya jeton
 * gibi hassas veriler günlüğe yazılmaz; yalnızca olay ve kimlikler yazılır.
 */
final class Logger
{
    public function __construct(private readonly string $file, private readonly ?ClockInterface $clock = null)
    {
    }

    /** @param array<string, scalar|null> $context */
    public function info(string $event, array $context = []): void
    {
        $this->write('INFO', $event, $context);
    }

    /** @param array<string, scalar|null> $context */
    public function warning(string $event, array $context = []): void
    {
        $this->write('UYARI', $event, $context);
    }

    /** @param array<string, scalar|null> $context */
    public function error(string $event, array $context = []): void
    {
        $this->write('HATA', $event, $context);
    }

    /** @param array<string, scalar|null> $context */
    private function write(string $level, string $event, array $context): void
    {
        if ($this->file === '') {
            return;
        }
        $time = $this->clock?->now() ?? new \DateTimeImmutable();
        $line = sprintf(
            "[%s] %s %s %s\n",
            $time->format('Y-m-d H:i:s'),
            $level,
            $event,
            $context === [] ? '' : json_encode($context, JSON_UNESCAPED_UNICODE)
        );
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
    }
}
