<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Settings;

/**
 * Test ayarları. Testler JSON deposunda, her test için ayrı geçici klasörde koşar.
 * Eşzamanlılık testinin işçi süreçleri de aynı ayarları kullanır.
 */
final class TestSettings
{
    public static function create(string $dir, string $logFile = '', bool $mailInbox = false): Settings
    {
        $root = dirname(__DIR__, 2);

        return new Settings(
            dataDir: $dir . '/data',
            uploadDir: $dir . '/uploads',
            logFile: $logFile,
            mailDir: $dir . '/mail',
            viewsDir: $root . '/views',
            backupDir: $dir . '/backups',
            mailInbox: $mailInbox,
        );
    }
}
