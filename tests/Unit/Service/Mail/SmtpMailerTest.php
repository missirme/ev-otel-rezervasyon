<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Mail;

use App\Exception\MailDeliveryException;
use App\Service\Mail\SmtpMailer;
use App\Support\FrozenClock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * FR-04, gerçek e-posta seçeneği: SmtpMailer, testte açılan sahte bir SMTP sunucusuyla (tests/bin/sahte-smtp.php)
 * konuşur. Komut sırası, kimlik doğrulama, UTF-8 konu ve gövde kodlaması ile hata durumları denetlenir.
 */
#[Group('FR-04')]
final class SmtpMailerTest extends TestCase
{
    private const USER = 'kullanici@ornek.test';
    private const PASSWORD = 'gizli-parola-123';

    /** @var resource|null */
    private $server = null;
    /** @var array<int, resource> */
    private array $pipes = [];
    private string $transcript = '';

    protected function tearDown(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if ($this->transcript !== '' && is_file($this->transcript)) {
            unlink($this->transcript);
        }
    }

    /** Sahte sunucuyu başlatır ve dinlediği portu döndürür. */
    private function startServer(string $mode = 'tamam'): int
    {
        $this->transcript = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'evotel-smtp-' . bin2hex(random_bytes(6)) . '.json';
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/bin/sahte-smtp.php', $this->transcript, $mode],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        $this->server = $process;
        $this->pipes = $pipes;
        $port = (int) trim((string) fgets($pipes[1]));
        if ($port <= 0) {
            // stderr yalnızca hata durumunda okunur: sunucu çalışırken okumak, kapanana kadar bekletir
            self::fail('Sahte SMTP sunucusu başlamadı: ' . stream_get_contents($pipes[2]));
        }

        return $port;
    }

    /**
     * Sunucu, bağlantı kapanınca kaydı yazıp çıkar; kayıt okunur.
     *
     * @return list<string|list<string>>
     */
    private function transcript(): array
    {
        if (is_resource($this->server)) {
            proc_close($this->server);
            $this->server = null;
        }
        /** @var list<string|list<string>> $log */
        $log = json_decode((string) file_get_contents($this->transcript), true, 512, JSON_THROW_ON_ERROR);

        return $log;
    }

    private function mailer(int $port, string $secure = 'yok'): SmtpMailer
    {
        return new SmtpMailer('127.0.0.1', $port, $secure, self::USER, self::PASSWORD, 'evotel@ornek.test', 'EvOtel', new FrozenClock('2026-11-01 10:00:00'), 5.0);
    }

    public function testSendsUtf8MessageAfterAuthenticating(): void
    {
        $port = $this->startServer();

        $this->mailer($port)->send('ali@evotel.test', 'Parola sıfırlama', "Merhaba Ali,\nBağlantı: http://localhost:8000/parola-sifirla/abc");

        $log = $this->transcript();
        self::assertIsString($log[0]);
        self::assertStringStartsWith('EHLO ', $log[0]);
        self::assertSame('AUTH PLAIN ' . base64_encode("\0" . self::USER . "\0" . self::PASSWORD), $log[1]);
        self::assertSame(['MAIL FROM:<evotel@ornek.test>', 'RCPT TO:<ali@evotel.test>', 'DATA'], array_slice($log, 2, 3));
        self::assertSame('QUIT', $log[6]);

        self::assertIsArray($log[5]);
        [$headers, $body] = explode("\r\n\r\n", $log[5][1], 2);
        self::assertStringContainsString("\r\nSubject: =?UTF-8?B?" . base64_encode('Parola sıfırlama') . '?=', $headers);
        self::assertStringContainsString('From: EvOtel <evotel@ornek.test>', $headers);
        self::assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $headers);
        self::assertStringContainsString('Date: Sun, 01 Nov 2026 10:00:00', $headers);
        self::assertSame("Merhaba Ali,\r\nBağlantı: http://localhost:8000/parola-sifirla/abc", base64_decode(str_replace("\r\n", '', $body), true));
    }

    public function testRejectedLoginIsReportedWithoutLeakingThePassword(): void
    {
        $port = $this->startServer('red');

        try {
            $this->mailer($port)->send('ali@evotel.test', 'Konu', 'Gövde');
            self::fail('Reddedilen kimlik doğrulama hata vermeli');
        } catch (MailDeliveryException $e) {
            self::assertStringContainsString('AUTH PLAIN', $e->getMessage());
            self::assertStringContainsString('535', $e->getMessage());
            self::assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            self::assertStringNotContainsString(base64_encode("\0" . self::USER . "\0" . self::PASSWORD), $e->getMessage());
        }
        self::assertNotContains('MAIL FROM:<evotel@ornek.test>', $this->transcript(), 'Kimlik doğrulanmadan ileti gönderilmez');
    }

    public function testStartTlsIsRequiredWhenConfigured(): void
    {
        $port = $this->startServer();

        $this->expectException(MailDeliveryException::class);
        $this->expectExceptionMessage('STARTTLS');
        $this->mailer($port, 'tls')->send('ali@evotel.test', 'Konu', 'Gövde');
    }

    public function testProbeAuthenticatesWithoutSendingAMessage(): void
    {
        $port = $this->startServer();

        $features = $this->mailer($port)->probe();

        self::assertContains('AUTH PLAIN LOGIN', $features);
        $log = $this->transcript();
        self::assertCount(3, $log, 'EHLO, AUTH ve QUIT; MAIL FROM gönderilmez');
        self::assertSame('QUIT', $log[2]);
    }

    public function testUnreachableServerIsReported(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket); // port artık boş: bağlantı reddedilir

        $this->expectException(MailDeliveryException::class);
        $this->expectExceptionMessage('bağlanılamadı');
        $this->mailer((int) substr($name, (int) strrpos($name, ':') + 1))->send('ali@evotel.test', 'Konu', 'Gövde');
    }

    public function testRecipientWithALineBreakIsRefusedBeforeConnecting(): void
    {
        $this->expectException(MailDeliveryException::class);
        $this->expectExceptionMessage('alıcı');
        $this->mailer(1)->send("ali@evotel.test\r\nBcc: kotu@ornek.test", 'Konu', 'Gövde');
    }
}
