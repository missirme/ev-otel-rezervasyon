<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = ['Content-Type' => 'text/html; charset=UTF-8'],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status);
    }

    /**
     * JSON yanıt (Etkileşim standardı: JSON döndüren AJAX uç noktası).
     *
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return new self($body, $status, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** POST sonrası yönlendirme (Post/Redirect/Get): sayfa yenilense de form ikinci kez gönderilmez. */
    public static function redirect(string $url): self
    {
        return new self('', 303, ['Location' => $url]);
    }

    /**
     * Bonus: CSV dışa aktarma. Excel'in Türkçe karakterleri tanıması için UTF-8 BOM eklenir.
     *
     * @param list<list<string>> $rows
     */
    public static function csv(string $fileName, array $rows): self
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return self::html('CSV oluşturulamadı.', 500);
        }
        foreach ($rows as $row) {
            fputcsv($handle, $row, ';', '"', '\\');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return new self("\xEF\xBB\xBF" . $csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }

    public static function file(string $path, string $mime): self
    {
        return new self((string) file_get_contents($path), 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
