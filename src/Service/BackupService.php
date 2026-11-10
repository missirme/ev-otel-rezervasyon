<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\PersistenceException;
use App\Exception\ValidationException;
use App\Repository\DataSnapshotInterface;
use App\Support\ClockInterface;
use App\Support\Logger;
use DateTimeImmutable;

/**
 * NFR-09: günlük yedek, son 7 yedeğin saklanması ve geri yükleme.
 *
 * Yedek, depodan bağımsız tek bir JSON dosyasıdır (storage/backups/yedek-<yılaygün>-<saatdakikasaniye>.json, ör. yedek-20261108-062847.json):
 * tüm tabloların satırları + satır sayıları + SHA-256 özeti. Geri yüklemede özet doğrulanır;
 * bozuk veya elle değiştirilmiş yedek yüklenmez. Geri yüklemeden hemen önce mevcut durumun da
 * yedeği alınır (yanlış yedek seçilirse geri dönülebilsin).
 */
final class BackupService
{
    public const KEEP = 7;
    private const FORMAT_VERSION = 1;
    private const NAME_PATTERN = '/^yedek-(\d{8})-(\d{6})(?:-\d+)?\.json$/';

    public function __construct(
        private readonly DataSnapshotInterface $snapshot,
        private readonly string $directory,
        private readonly string $storage,
        private readonly ClockInterface $clock,
        private readonly Logger $logger,
    ) {
    }

    /** Yedek alır ve en yeni 7 yedeği bırakır. Dosya adını döndürür. */
    public function create(): string
    {
        $tables = $this->snapshot->exportTables();
        $payload = (string) json_encode($tables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $now = $this->clock->now();
        $document = [
            'surum' => self::FORMAT_VERSION,
            'olusturma' => $now->format('Y-m-d H:i:s'),
            'depo' => $this->storage,
            'satir_sayilari' => array_map('count', $tables),
            'ozet' => hash('sha256', $payload),
            'tablolar' => $tables,
        ];

        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new PersistenceException('Yedek klasörü oluşturulamadı.');
        }
        $base = 'yedek-' . $now->format('Ymd-His');
        $name = $base . '.json';
        for ($i = 2; is_file($this->directory . '/' . $name); $i++) {
            $name = $base . '-' . $i . '.json';
        }
        $temp = $this->directory . '/.' . $name . '.tmp';
        if (
            file_put_contents($temp, json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false
            || !rename($temp, $this->directory . '/' . $name)
        ) {
            @unlink($temp);
            throw new PersistenceException('Yedek dosyası yazılamadı.');
        }
        $removed = $this->prune();
        $this->logger->info('yedek_alindi', ['dosya' => $name, 'silinen_eski' => $removed]);

        return $name;
    }

    /** Zamanlanmış görev için: bugün alınmış yedek yoksa yedek alır (günde en az bir yedek). */
    public function createDailyIfMissing(): ?string
    {
        $today = $this->clock->today()->format('Ymd');
        foreach ($this->list() as $backup) {
            if ($backup['tarih']->format('Ymd') === $today) {
                return null;
            }
        }

        return $this->create();
    }

    /**
     * En yeni önce.
     *
     * @return list<array{ad: string, tarih: DateTimeImmutable, boyut: int}>
     */
    public function list(): array
    {
        $backups = [];
        foreach (glob($this->directory . '/yedek-*.json') ?: [] as $path) {
            $name = basename($path);
            if (preg_match(self::NAME_PATTERN, $name, $m) !== 1) {
                continue;
            }
            $date = DateTimeImmutable::createFromFormat('Ymd His', $m[1] . ' ' . $m[2]);
            if ($date !== false) {
                $backups[] = ['ad' => $name, 'tarih' => $date, 'boyut' => (int) filesize($path)];
            }
        }
        usort($backups, fn (array $a, array $b): int => [$b['tarih'], $b['ad']] <=> [$a['tarih'], $a['ad']]);

        return $backups;
    }

    /**
     * Yedeği geri yükler. Önce mevcut durumun yedeğini alır, sonra özet doğrulanmış yedeği tek işlemde yükler.
     *
     * @return array{oncesi: string, satirlar: array<string, int>}
     */
    public function restore(string $name): array
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1 || !is_file($this->directory . '/' . $name)) {
            throw ValidationException::single('yedek', 'Yedek bulunamadı: ' . $name);
        }
        $document = json_decode((string) file_get_contents($this->directory . '/' . $name), true);
        if (!is_array($document) || ($document['surum'] ?? null) !== self::FORMAT_VERSION || !is_array($document['tablolar'] ?? null)) {
            throw new PersistenceException('Yedek dosyası okunamadı veya biçimi tanınmıyor: ' . $name);
        }
        /** @var array<string, list<array<string, mixed>>> $tables */
        $tables = $document['tablolar'];
        $payload = (string) json_encode($tables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!hash_equals((string) ($document['ozet'] ?? ''), hash('sha256', $payload))) {
            throw new PersistenceException('Yedeğin özeti tutmuyor; dosya bozulmuş veya değiştirilmiş. Geri yükleme yapılmadı.');
        }

        $before = $this->create();
        $this->snapshot->importTables($tables);
        $this->logger->info('yedek_geri_yuklendi', ['dosya' => $name, 'oncesi' => $before]);

        return ['oncesi' => $before, 'satirlar' => array_map('count', $tables)];
    }

    /** @return int silinen eski yedek sayısı */
    private function prune(): int
    {
        $removed = 0;
        foreach (array_slice($this->list(), self::KEEP) as $old) {
            if (@unlink($this->directory . '/' . $old['ad'])) {
                $removed++;
            }
        }

        return $removed;
    }
}
