<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Mail;

use App\Exception\MailDeliveryException;
use App\Service\Mail\FileMailer;
use App\Support\FrozenClock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\AppTestCase;

/** FR-04: sıfırlama iletisi simüle posta kutusuna yazılır; yazılamazsa "gönderildi" denmez. */
#[Group('FR-04')]
final class FileMailerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'evotel-posta-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        AppTestCase::removeDirectory($this->dir);
        if (is_file($this->dir)) {
            unlink($this->dir);
        }
    }

    public function testMessageIsWrittenWithSingleLineHeaders(): void
    {
        (new FileMailer($this->dir, new FrozenClock('2026-11-01 10:00:00')))->send('ali@evotel.test', "Parola\nBcc: kotu@ornek.test", 'Gövde');

        $files = glob($this->dir . '/*.txt') ?: [];
        self::assertCount(1, $files);
        self::assertMatchesRegularExpression('/^20261101-100000-[0-9a-f]{6}\.txt$/', basename($files[0]));
        self::assertSame("Kime: ali@evotel.test\nKonu: Parola Bcc: kotu@ornek.test\n\nGövde\n", file_get_contents($files[0]), 'Konu satırına yeni başlık eklenemez');
    }

    public function testFailureIsReportedInsteadOfPretendingTheMailWasSent(): void
    {
        file_put_contents($this->dir, 'klasör yerine dosya'); // posta klasörü oluşturulamaz

        $this->expectException(MailDeliveryException::class);
        (new FileMailer($this->dir, new FrozenClock('2026-11-01 10:00:00')))->send('ali@evotel.test', 'Parola sıfırlama', 'Gövde');
    }
}
