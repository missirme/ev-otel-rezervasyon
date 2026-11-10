<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Exception\MailDeliveryException;
use App\Support\ClockInterface;

/**
 * Simüle e-posta kutusu: her ileti storage/mail altına ayrı bir .txt dosyası olarak yazılır.
 * Bu klasör uygulama günlüğü değildir; demo sırasında "gelen kutusu" yerine açılır.
 * Yazılamazsa MailDeliveryException fırlatılır; kullanıcıya "gönderildi" denmez.
 */
final class FileMailer implements MailerInterface
{
    public function __construct(private readonly string $directory, private readonly ClockInterface $clock)
    {
    }

    public function send(string $to, string $subject, string $body): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new MailDeliveryException('Posta kutusu klasörü oluşturulamadı: ' . $this->directory);
        }
        $file = sprintf(
            '%s/%s-%s.txt',
            $this->directory,
            $this->clock->now()->format('Ymd-His'),
            bin2hex(random_bytes(3))
        );
        $content = 'Kime: ' . self::oneLine($to) . "\nKonu: " . self::oneLine($subject) . "\n\n" . $body . "\n";
        if (@file_put_contents($file, $content, LOCK_EX) === false) {
            throw new MailDeliveryException('İleti yazılamadı: ' . $file);
        }
    }

    /** Başlık satırına yeni satır karışmasın (başlık enjeksiyonu). */
    private static function oneLine(string $value): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $value));
    }
}
