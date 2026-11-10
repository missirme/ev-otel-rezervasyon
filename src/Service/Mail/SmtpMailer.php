<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Exception\MailDeliveryException;
use App\Support\ClockInterface;

/**
 * Gerçek e-posta gönderimi (SMTP, RFC 5321). Ders kuralı gereği harici kütüphane kullanılmaz.
 * Desteklenenler: STARTTLS (587) veya doğrudan TLS (465), AUTH PLAIN / LOGIN, UTF-8 konu (RFC 2047)
 * ve base64 gövde. Varsayılan değildir; config/yerel.php içinde 'mail_driver' => 'smtp' seçilince kullanılır.
 * Hata iletileri parolayı, kimlik doğrulama satırını veya e-posta adreslerini içermez (günlüğe yazılırlar).
 */
final class SmtpMailer implements MailerInterface
{
    private const CRYPTO = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $secure,
        private readonly string $user,
        private readonly string $password,
        private readonly string $from,
        private readonly string $fromName,
        private readonly ClockInterface $clock,
        private readonly float $timeoutSeconds = 10.0,
    ) {
    }

    public function send(string $to, string $subject, string $body): void
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailDeliveryException('Geçersiz alıcı adresi.');
        }
        [$socket] = $this->open();
        try {
            $this->command($socket, 'MAIL FROM:<' . $this->from . '>', [250], 'MAIL FROM');
            $this->command($socket, 'RCPT TO:<' . $to . '>', [250, 251], 'RCPT TO');
            $this->command($socket, 'DATA', [354]);
            $this->write($socket, $this->message($to, $subject, $body) . "\r\n.\r\n");
            $this->expect($socket, [250], 'ileti gövdesi');
            $this->quit($socket);
        } finally {
            fclose($socket);
        }
    }

    /**
     * İleti göndermeden bağlantıyı dener: bağlanır, gerekiyorsa TLS'e geçer ve kimlik doğrular.
     *
     * @return list<string> sunucunun bildirdiği yetenekler (ör. "AUTH PLAIN LOGIN")
     */
    public function probe(): array
    {
        [$socket, $features] = $this->open();
        try {
            $this->quit($socket);
        } finally {
            fclose($socket);
        }

        return $features;
    }

    /** @return array{0: resource, 1: list<string>} açık bağlantı ve sunucunun yetenekleri */
    private function open(): array
    {
        if ($this->host === '') {
            throw new MailDeliveryException('SMTP sunucusu ayarlanmamış (config/yerel.php: smtp.host).');
        }
        if (filter_var($this->from, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailDeliveryException('Gönderen adresi geçersiz (config/yerel.php: mail_from veya smtp.user).');
        }
        $socket = $this->connect();
        try {
            $this->expect($socket, [220], 'karşılama');
            $features = $this->ehlo($socket);
            if ($this->secure === 'tls') {
                if (!in_array('STARTTLS', $features, true)) {
                    throw new MailDeliveryException('SMTP sunucusu STARTTLS desteklemiyor; smtp.secure ayarını kontrol edin.');
                }
                $this->command($socket, 'STARTTLS', [220]);
                if (@stream_socket_enable_crypto($socket, true, self::CRYPTO) !== true) {
                    throw new MailDeliveryException('TLS bağlantısı kurulamadı (sunucu sertifikası doğrulanamamış olabilir).');
                }
                $features = $this->ehlo($socket);
            }
            if ($this->user !== '') {
                $this->authenticate($socket, $features);
            }

            return [$socket, $features];
        } catch (MailDeliveryException $e) {
            fclose($socket);
            throw $e;
        }
    }

    /** @return resource */
    private function connect()
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $this->host,
            'SNI_enabled' => true,
            'crypto_method' => self::CRYPTO,
        ]]);
        $address = ($this->secure === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $socket = @stream_socket_client($address, $errorCode, $errorText, $this->timeoutSeconds, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            $reason = trim(mb_scrub((string) $errorText, 'UTF-8'));
            throw new MailDeliveryException(sprintf(
                'SMTP sunucusuna bağlanılamadı (%s:%d): %s',
                $this->host,
                $this->port,
                $reason !== '' ? $reason : 'hata kodu ' . $errorCode
            ));
        }
        stream_set_timeout($socket, max(1, (int) ceil($this->timeoutSeconds)));

        return $socket;
    }

    /**
     * @param resource $socket
     * @return list<string> büyük harfe çevrilmiş yetenek satırları (ör. "STARTTLS", "AUTH PLAIN LOGIN")
     */
    private function ehlo($socket): array
    {
        $lines = $this->command($socket, 'EHLO ' . self::heloName(), [250]);
        array_shift($lines); // ilk satır sunucunun selamıdır

        return array_map(static fn (string $line): string => strtoupper(trim($line)), $lines);
    }

    /**
     * @param resource $socket
     * @param list<string> $features
     */
    private function authenticate($socket, array $features): void
    {
        $mechanisms = [];
        foreach ($features as $feature) {
            if (str_starts_with($feature, 'AUTH ') || str_starts_with($feature, 'AUTH=')) {
                $mechanisms = array_merge($mechanisms, preg_split('/[\s=]+/', substr($feature, 5)) ?: []);
            }
        }
        if (in_array('PLAIN', $mechanisms, true)) {
            $this->command($socket, 'AUTH PLAIN ' . base64_encode("\0" . $this->user . "\0" . $this->password), [235], 'AUTH PLAIN');
        } elseif (in_array('LOGIN', $mechanisms, true)) {
            $this->command($socket, 'AUTH LOGIN', [334]);
            $this->command($socket, base64_encode($this->user), [334], 'AUTH LOGIN (kullanıcı adı)');
            $this->command($socket, base64_encode($this->password), [235], 'AUTH LOGIN (parola)');
        } else {
            throw new MailDeliveryException('SMTP sunucusu desteklenen bir kimlik doğrulama yöntemi (PLAIN veya LOGIN) sunmuyor.');
        }
    }

    /**
     * Komutu gönderir ve yanıt kodunu doğrular.
     *
     * @param resource $socket
     * @param list<int> $expected
     * @param string|null $label hata iletisinde komutun yerine yazılır (kimlik bilgisi veya adres sızmasın)
     * @return list<string> yanıt satırlarının metni
     */
    private function command($socket, string $line, array $expected, ?string $label = null): array
    {
        $this->write($socket, $line . "\r\n");

        return $this->expect($socket, $expected, $label ?? $line);
    }

    /** @param resource $socket */
    private function write($socket, string $data): void
    {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $count = @fwrite($socket, substr($data, $written));
            if ($count === false || $count === 0) {
                throw new MailDeliveryException('SMTP bağlantısı yazma sırasında kesildi.');
            }
            $written += $count;
        }
    }

    /**
     * Sunucunun (çok satırlı olabilen) yanıtını okur.
     *
     * @param resource $socket
     * @param list<int> $expected
     * @return list<string> yanıt satırlarının metni (kod hariç)
     */
    private function expect($socket, array $expected, string $label): array
    {
        $lines = [];
        do {
            $line = fgets($socket, 4096);
            if ($line === false) {
                $timedOut = stream_get_meta_data($socket)['timed_out'];
                throw new MailDeliveryException('SMTP sunucusu yanıt vermedi (' . $label . ($timedOut ? ', zaman aşımı' : '') . ').');
            }
            $code = (int) substr($line, 0, 3);
            $lines[] = rtrim(substr($line, 4), "\r\n");
        } while (strlen($line) > 3 && $line[3] === '-');
        if (!in_array($code, $expected, true)) {
            throw new MailDeliveryException(sprintf('SMTP sunucusu %s adımını reddetti: %d %s', $label, $code, self::serverText($lines)));
        }

        return $lines;
    }

    /** @param resource $socket */
    private function quit($socket): void
    {
        try {
            $this->command($socket, 'QUIT', [221]);
        } catch (MailDeliveryException) {
            // İleti zaten kabul edildi; kapanış yanıtının önemi yok.
        }
    }

    private function message(string $to, string $subject, string $body): string
    {
        $domain = substr((string) strrchr($this->from, '@'), 1);
        $name = self::oneLine($this->fromName);
        $headers = [
            'Date: ' . $this->clock->now()->format(DATE_RFC2822),
            'From: ' . ($name === '' ? '' : self::encodeHeader($name) . ' ') . '<' . $this->from . '>',
            'To: <' . $to . '>',
            'Subject: ' . self::encodeHeader(self::oneLine($subject)),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . ($domain !== '' ? $domain : 'localhost') . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $text = str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $body));

        // base64 satırları "." ile başlamaz; gövdede ayrıca nokta doldurma (dot-stuffing) gerekmez.
        return implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($text), 76, "\r\n"));
    }

    /** RFC 2047: ASCII dışı başlık değeri, her biri en çok 75 karakterlik =?UTF-8?B?...?= parçalarına bölünür. */
    private static function encodeHeader(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9 ._-]*\z/', $value) === 1) {
            return $value;
        }
        $words = [];
        $chunk = '';
        foreach (mb_str_split($value, 1, 'UTF-8') as $char) {
            if (strlen($chunk . $char) > 45) {
                $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
                $chunk = '';
            }
            $chunk .= $char;
        }
        $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';

        return implode("\r\n ", $words);
    }

    /** Başlık satırına yeni satır karışmasın (başlık enjeksiyonu). */
    private static function oneLine(string $value): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $value));
    }

    /** @param list<string> $lines sunucunun metni; e-posta adresleri günlüğe yazılmaz */
    private static function serverText(array $lines): string
    {
        return (string) preg_replace('/[^\s<>()]+@[^\s<>()]+/', '<adres>', mb_scrub(implode(' ', $lines), 'UTF-8'));
    }

    private static function heloName(): string
    {
        $name = gethostname();

        return is_string($name) && preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}\z/', $name) === 1 ? $name : 'localhost';
    }
}
