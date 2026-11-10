<?php

declare(strict_types=1);

namespace App\Support;

/**
 * config/app.php değerlerinin tip güvenli hâli. Servisler bu nesneyi alır.
 */
final class Settings
{
    public function __construct(
        public readonly string $appName = 'EvOtel',
        public readonly string $timezone = 'Europe/Istanbul',
        public readonly string $dataDir = '',
        public readonly string $uploadDir = '',
        public readonly string $logFile = '',
        public readonly string $mailDir = '',
        public readonly string $viewsDir = '',
        public readonly int $sessionTimeoutMinutes = 30,
        public readonly int $holdMinutesInstant = 10,
        public readonly int $holdHoursApproval = 24,
        public readonly int $maxNights = 30,
        public readonly int $maxDaysAhead = 365,
        public readonly int $alternativeCount = 3,
        public readonly float $lockTimeoutSeconds = 5.0,
        public readonly int $lockRetries = 2,
        public readonly int $loginMaxAttempts = 5,
        public readonly int $loginLockMinutes = 15,
        public readonly int $passwordResetMinutes = 60,
        public readonly int $uploadMaxBytes = 2097152,
        public readonly int $perPage = 6,
        public readonly string $backupDir = '',
        public readonly string $mailDriver = 'dosya',
        public readonly bool $mailInbox = false,
        public readonly string $mailFrom = '',
        public readonly string $mailFromName = 'EvOtel',
        public readonly string $smtpHost = '',
        public readonly int $smtpPort = 587,
        public readonly string $smtpSecure = 'tls',
        public readonly string $smtpUser = '',
        public readonly string $smtpPassword = '',
    ) {
    }

    /** FR-04 e-postası gerçekten gönderilir mi (SMTP), yoksa simüle posta kutusuna mı yazılır (storage/mail)? */
    public function usesSmtp(): bool
    {
        return $this->mailDriver === 'smtp';
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $str = static fn (string $k, string $d): string => isset($config[$k]) && is_string($config[$k]) ? $config[$k] : $d;
        $int = static fn (string $k, int $d): int => isset($config[$k]) && is_int($config[$k]) ? $config[$k] : $d;
        $flt = static fn (string $k, float $d): float => isset($config[$k]) && is_numeric($config[$k]) ? (float) $config[$k] : $d;
        $bool = static fn (string $k, bool $d): bool => isset($config[$k]) && is_bool($config[$k]) ? $config[$k] : $d;
        $smtp = is_array($config['smtp'] ?? null) ? $config['smtp'] : [];
        $smtpStr = static fn (string $k, string $d): string => isset($smtp[$k]) && is_string($smtp[$k]) ? $smtp[$k] : $d;

        return new self(
            appName: $str('app_name', 'EvOtel'),
            timezone: $str('timezone', 'Europe/Istanbul'),
            dataDir: $str('data_dir', ''),
            uploadDir: $str('upload_dir', ''),
            logFile: $str('log_file', ''),
            mailDir: $str('mail_dir', ''),
            viewsDir: $str('views_dir', ''),
            sessionTimeoutMinutes: $int('session_timeout_minutes', 30),
            holdMinutesInstant: $int('hold_minutes_instant', 10),
            holdHoursApproval: $int('hold_hours_approval', 24),
            maxNights: $int('max_nights', 30),
            maxDaysAhead: $int('max_days_ahead', 365),
            alternativeCount: $int('alternative_count', 3),
            lockTimeoutSeconds: $flt('lock_timeout_seconds', 5.0),
            lockRetries: $int('lock_retries', 2),
            loginMaxAttempts: $int('login_max_attempts', 5),
            loginLockMinutes: $int('login_lock_minutes', 15),
            passwordResetMinutes: $int('password_reset_minutes', 60),
            uploadMaxBytes: $int('upload_max_bytes', 2097152),
            perPage: $int('per_page', 6),
            backupDir: $str('backup_dir', ''),
            mailDriver: $str('mail_driver', 'dosya') === 'smtp' ? 'smtp' : 'dosya',
            mailInbox: $bool('mail_inbox', false),
            mailFrom: $str('mail_from', ''),
            mailFromName: $str('mail_from_name', 'EvOtel'),
            smtpHost: $smtpStr('host', ''),
            smtpPort: isset($smtp['port']) && is_int($smtp['port']) ? $smtp['port'] : 587,
            smtpSecure: in_array($smtpStr('secure', 'tls'), ['tls', 'ssl', 'yok'], true) ? $smtpStr('secure', 'tls') : 'tls',
            smtpUser: $smtpStr('user', ''),
            smtpPassword: $smtpStr('password', ''),
        );
    }
}
