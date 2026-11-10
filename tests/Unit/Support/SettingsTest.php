<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Core\Container;
use App\Support\Settings;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** SRS'teki sayısal kararlar tek yerde (config/app.php) ve SRS ile birebir aynıdır (SRS 11.4, risk R12). */
final class SettingsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function config(): array
    {
        return require __DIR__ . '/../../../config/app.php';
    }

    #[Group('BR-14')]
    #[Group('BR-15')]
    #[Group('NFR-13')]
    #[Group('NFR-15')]
    #[Group('NFR-19')]
    #[Group('FR-04')]
    #[Group('FR-36')]
    public function testConfiguredNumbersMatchTheSrs(): void
    {
        $s = Settings::fromArray(self::config());

        self::assertSame([10, 24], [$s->holdMinutesInstant, $s->holdHoursApproval], 'BR-15: anında 10 dk, onaylı 24 saat');
        self::assertSame([30, 365], [$s->maxNights, $s->maxDaysAhead], 'BR-14: en çok 30 gece, en çok 365 gün ileri');
        self::assertSame([5.0, 2], [$s->lockTimeoutSeconds, $s->lockRetries], 'NFR-13 / SRS 4.5: 5 sn kilit, 2 yeniden deneme');
        self::assertSame([5, 15], [$s->loginMaxAttempts, $s->loginLockMinutes], 'NFR-15: 5 hatalı giriş, 15 dk kısıt');
        self::assertSame(60, $s->passwordResetMinutes, 'FR-04: jeton 60 dk geçerli');
        self::assertSame(2 * 1024 * 1024, $s->uploadMaxBytes, 'NFR-19: en çok 2 MB');
        self::assertSame(3, $s->alternativeCount, 'FR-36: en çok 3 alternatif');
    }

    #[Group('NFR-17')]
    public function testServerTimezoneIsIstanbul(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $container = Container::fromConfig(self::config());

            self::assertSame('Europe/Istanbul', date_default_timezone_get());
            self::assertSame('Europe/Istanbul', $container->clock->now()->getTimezone()->getName());
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testDataIsStoredInJsonFiles(): void
    {
        // Aşama 2 kuralı: veriler storage/data altındaki JSON dosyalarındadır.
        $settings = Settings::fromArray(self::config());
        self::assertStringEndsWith('storage/data', str_replace('\\', '/', $settings->dataDir));
    }

    #[Group('FR-04')]
    public function testMailIsSimulatedUnlessSmtpIsChosenExplicitly(): void
    {
        $defaults = Settings::fromArray([]);
        self::assertFalse($defaults->usesSmtp(), 'Varsayılan: simüle posta kutusu (storage/mail)');
        self::assertFalse($defaults->mailInbox, 'Posta kutusu sayfası yalnızca ayarla açılır');
        self::assertSame('dosya', Settings::fromArray(['mail_driver' => 'pop3'])->mailDriver, 'Geçersiz değer simüleye düşer');

        $smtp = Settings::fromArray([
            'mail_driver' => 'smtp',
            'mail_inbox' => true,
            'smtp' => ['host' => 'smtp.ornek.test', 'port' => 465, 'secure' => 'ssl', 'user' => 'kullanici@ornek.test', 'password' => 'gizli'],
        ]);
        self::assertTrue($smtp->usesSmtp());
        self::assertTrue($smtp->mailInbox);
        self::assertSame(['smtp.ornek.test', 465, 'ssl', 'kullanici@ornek.test'], [$smtp->smtpHost, $smtp->smtpPort, $smtp->smtpSecure, $smtp->smtpUser]);
        self::assertSame('tls', Settings::fromArray(['smtp' => ['secure' => 'bilinmeyen']])->smtpSecure, 'Geçersiz güvenlik ayarı STARTTLS\'e düşer');
    }
}
