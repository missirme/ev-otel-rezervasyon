<?php

declare(strict_types=1);

namespace App\Core;

/** HTTP isteği. $_GET/$_POST/$_FILES yalnızca burada ve Controller'da okunur; Service okumaz. */
final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @param array<string, mixed> $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $files = [],
        public readonly array $server = [],
        public readonly string $basePath = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $script = is_string($_SERVER['SCRIPT_NAME'] ?? null) ? $_SERVER['SCRIPT_NAME'] : '/index.php';
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
        $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        if (str_starts_with($path, '/index.php')) {
            $path = substr($path, strlen('/index.php'));
        }
        $path = '/' . trim($path, '/');

        return new self(
            strtoupper(is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET'),
            $path,
            $_GET,
            $_POST,
            $_FILES,
            $_SERVER,
            $base,
        );
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /** Önce form (POST), sonra adres (GET) değerine bakar; dizi gelirse varsayılanı döndürür. */
    public function input(string $key, string $default = ''): string
    {
        $value = $this->post[$key] ?? $this->query[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    public function query(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = trim($this->input($key));

        return preg_match('/^-?\d{1,9}$/', $value) ? (int) $value : $default;
    }

    public function nullableInt(string $key): ?int
    {
        $value = trim($this->input($key));

        return preg_match('/^-?\d{1,9}$/', $value) ? (int) $value : null;
    }

    /** @return list<string> çoklu seçimler (ör. olanak[]) */
    public function list(string $key): array
    {
        $value = $this->post[$key] ?? $this->query[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    /** @return array<string, mixed> */
    public function file(string $key): array
    {
        $file = $this->files[$key] ?? [];

        return is_array($file) ? $file : [];
    }

    public function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    public function isHttps(): bool
    {
        $https = $this->server['HTTPS'] ?? '';

        return is_string($https) && $https !== '' && strtolower($https) !== 'off';
    }

    /** İstek bu bilgisayardan mı geldi? Geliştirme araçları (ör. /posta-kutusu) yalnızca yerelden açılır. */
    public function isFromLoopback(): bool
    {
        $address = $this->server['REMOTE_ADDR'] ?? '';

        return is_string($address) && in_array($address, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true);
    }

    /** Girdide bozuk kodlanmış (UTF-8 olmayan) metin var mı? Böyle bir metin JSON veri dosyasına yazılamaz. */
    public function hasInvalidEncoding(): bool
    {
        return !mb_check_encoding($this->query, 'UTF-8') || !mb_check_encoding($this->post, 'UTF-8');
    }

    /** Adres çubuğundaki yol + sorgu (giriş sonrası geri dönmek için). */
    public function fullPath(): string
    {
        return $this->path . ($this->query === [] ? '' : '?' . http_build_query($this->query));
    }
}
