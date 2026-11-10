<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Service\Dto\MailMessage;
use DateTimeImmutable;

/**
 * Simüle posta kutusunu okur (FileMailer'ın storage/mail altına yazdığı iletiler).
 * Gerçek e-posta servisi olmadığında parola sıfırlama bağlantısı /posta-kutusu sayfasında açılır.
 * İletilerde sıfırlama jetonu bulunduğu için sayfa yalnızca ayar açıkken ve yerel istekte görünür (MailboxController).
 */
final class FileMailbox
{
    /** FileMailer'ın dosya adı: yılaygün-saatdakikasaniye-6 onaltılık hane (klasör dışına çıkılamaz) */
    private const ID = '/^\d{8}-\d{6}-[0-9a-f]{6}\z/';

    public function __construct(private readonly string $directory, private readonly bool $enabled)
    {
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /** @return list<MailMessage> en yeni ileti başta */
    public function latest(int $limit = 50): array
    {
        $ids = [];
        foreach (is_dir($this->directory) ? (scandir($this->directory) ?: []) : [] as $name) {
            $id = substr($name, 0, -4);
            if (str_ends_with($name, '.txt') && preg_match(self::ID, $id) === 1) {
                $ids[] = $id;
            }
        }
        rsort($ids, SORT_STRING);
        $messages = [];
        foreach (array_slice($ids, 0, $limit) as $id) {
            $message = $this->find($id);
            if ($message !== null) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    public function find(string $id): ?MailMessage
    {
        if (preg_match(self::ID, $id) !== 1) {
            return null;
        }
        $file = $this->directory . '/' . $id . '.txt';
        $raw = is_file($file) ? file_get_contents($file) : false;
        if ($raw === false) {
            return null;
        }
        [$head, $body] = array_pad(explode("\n\n", str_replace("\r\n", "\n", $raw), 2), 2, '');
        $headers = [];
        foreach (explode("\n", $head) as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $headers[trim($name)] = trim($value);
        }
        $sentAt = DateTimeImmutable::createFromFormat('!Ymd-His', substr($id, 0, 15));

        return new MailMessage($id, $headers['Kime'] ?? '', $headers['Konu'] ?? '', rtrim($body), $sentAt === false ? null : $sentAt);
    }
}
