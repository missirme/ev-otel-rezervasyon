<?php

declare(strict_types=1);

namespace App\Repository\Json;

use App\Exception\BusyException;
use App\Exception\PersistenceException;
use App\Exception\UniqueConstraintException;
use JsonException;

/**
 * Aşama 2 veri deposu: her tablo storage/data/<tablo>.json dosyasıdır.
 *
 * Eşzamanlılık (SRS 4.5, risk R5):
 *  - Okuma-kontrol-yazma adımları tek bir dışlayıcı dosya kilidi (flock LOCK_EX) altında yapılır.
 *    Aynı anda gelen iki rezervasyon isteği bu kilitte sıraya girer.
 *  - Kilit 5 sn içinde alınamazsa (2 yeniden denemeyle) BusyException atılır; hiçbir şey yazılmaz.
 *  - Değişiklikler önce bellekte tutulur; işlem başarıyla biterse geçici dosyaya yazılıp
 *    yeniden adlandırılır (atomik değiştirme). Hata olursa bellek atılır: geri alma (rollback).
 *  - Yeniden adlandırmalar bir günlük (journal) dosyasıyla yapılır; sunucu yarıda kesilirse
 *    bir sonraki kilitte tamamlanır, böylece "yarım işlem" kalmaz (CS-12).
 *  - Benzersizlik kısıtları (UNIQUE) burada da uygulanır: çift rezervasyona karşı son savunma.
 *
 * Not: İç içe transaction() çağrıları dıştaki işlemin parçasıdır; hata dış işleme kadar yükselmelidir.
 */
class JsonDatabase
{
    private const LOCK_FILE = '.lock';
    private const JOURNAL_FILE = '.journal.json';

    /** @var array<string, array{pk: list<string>, auto: bool, unique: list<list<string>>}> */
    private array $schema;

    /** @var array<string, array{auto_increment: int, rows: list<array<string, mixed>>}> */
    private array $tables = [];

    /** @var array<string, true> */
    private array $dirty = [];

    /** @var array<string, array{stamp: string, data: array{auto_increment: int, rows: list<array<string, mixed>>}}> */
    private array $readCache = [];

    private int $depth = 0;

    /** @var resource|null */
    private $lockHandle = null;

    /**
     * @param array<string, array{pk: list<string>, auto: bool, unique: list<list<string>>}>|null $schema
     */
    public function __construct(
        private readonly string $dir,
        ?array $schema = null,
        private readonly float $lockTimeoutSeconds = 5.0,
        private readonly int $lockRetries = 2,
    ) {
        $this->schema = $schema ?? JsonSchema::tables();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new PersistenceException('Veri dizini oluşturulamadı: ' . $dir);
        }
    }

    public function directory(): string
    {
        return $this->dir;
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }

    /**
     * Fonksiyonu tek bir atomik işlem olarak çalıştırır.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->depth > 0) {
            $this->depth++;
            try {
                return $fn();
            } finally {
                $this->depth--;
            }
        }

        $this->acquireLock(LOCK_EX);
        try {
            $this->recoverJournal();
            $this->tables = [];
            $this->dirty = [];
            $this->depth = 1;
            $result = $fn();
            $this->commit();

            return $result;
        } finally {
            // Başarı veya hata: bellek temizlenir. Hata durumunda hiçbir dosya değişmemiştir.
            $this->depth = 0;
            $this->tables = [];
            $this->dirty = [];
            $this->releaseLock();
        }
    }

    /**
     * Tablo satırlarını döndürür (isteğe bağlı süzgeçle).
     *
     * @param (callable(array<string, mixed>): bool)|null $where
     * @return list<array<string, mixed>>
     */
    public function select(string $table, ?callable $where = null): array
    {
        $rows = $this->tableData($table)['rows'];
        if ($where === null) {
            return $rows;
        }

        return array_values(array_filter($rows, $where));
    }

    /**
     * @param callable(array<string, mixed>): bool $where
     * @return array<string, mixed>|null
     */
    public function first(string $table, callable $where): ?array
    {
        foreach ($this->tableData($table)['rows'] as $row) {
            if ($where($row)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Satır ekler; otomatik artan tablolarda id atanır. Benzersizlik kısıtı denetlenir.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function insert(string $table, array $row): array
    {
        if ($this->depth === 0) {
            return $this->transaction(fn (): array => $this->insert($table, $row));
        }
        $def = $this->definition($table);
        $data = $this->load($table);
        if ($def['auto']) {
            if (($row['id'] ?? null) === null) {
                $data['auto_increment']++;
                $row = ['id' => $data['auto_increment']] + $row;
            } elseif (is_int($row['id']) && $row['id'] > $data['auto_increment']) {
                $data['auto_increment'] = $row['id'];
            }
        }
        $this->assertUnique($table, $data['rows'], $row, null);
        $data['rows'][] = $row;
        $this->store($table, $data);

        return $row;
    }

    /**
     * Koşula uyan satırları günceller ve etkilenen satır sayısını döndürür
     * (SQL'deki UPDATE ... WHERE ve rowCount() karşılığı).
     *
     * @param callable(array<string, mixed>): bool $where
     * @param array<string, mixed> $changes
     */
    public function update(string $table, callable $where, array $changes): int
    {
        if ($this->depth === 0) {
            return $this->transaction(fn (): int => $this->update($table, $where, $changes));
        }
        $data = $this->load($table);
        $affected = 0;
        foreach ($data['rows'] as $i => $row) {
            if ($where($row)) {
                $new = array_merge($row, $changes);
                $this->assertUnique($table, $data['rows'], $new, $i);
                $data['rows'][$i] = $new;
                $affected++;
            }
        }
        if ($affected > 0) {
            $this->store($table, $data);
        }

        return $affected;
    }

    /** @param callable(array<string, mixed>): bool $where */
    public function delete(string $table, callable $where): int
    {
        if ($this->depth === 0) {
            return $this->transaction(fn (): int => $this->delete($table, $where));
        }
        $data = $this->load($table);
        $kept = [];
        $deleted = 0;
        foreach ($data['rows'] as $row) {
            if ($where($row)) {
                $deleted++;
            } else {
                $kept[] = $row;
            }
        }
        if ($deleted > 0) {
            $data['rows'] = $kept;
            $this->store($table, $data);
        }

        return $deleted;
    }

    /** Tüm tabloları boşaltır (örnek veri yüklemeden önce). */
    public function truncateAll(): void
    {
        $this->transaction(function (): void {
            foreach (array_keys($this->schema) as $table) {
                $this->store($table, ['auto_increment' => 0, 'rows' => []]);
            }
        });
    }

    // ---------------------------------------------------------------- iç işleyiş

    /** @return array{pk: list<string>, auto: bool, unique: list<list<string>>} */
    private function definition(string $table): array
    {
        if (!isset($this->schema[$table])) {
            throw new PersistenceException('Bilinmeyen tablo: ' . $table);
        }

        return $this->schema[$table];
    }

    /** @return array{auto_increment: int, rows: list<array<string, mixed>>} */
    private function tableData(string $table): array
    {
        $this->definition($table);
        if ($this->depth > 0) {
            return $this->load($table);
        }

        // İşlem dışı okuma: paylaşımlı kilitle tutarlı anlık görüntü
        $this->acquireLock(LOCK_SH);
        try {
            $this->recoverJournalIfPresentReadOnly();
            $path = $this->path($table);
            $content = is_file($path) ? file_get_contents($path) : '';
            if ($content === false) {
                throw new PersistenceException('Veri dosyası okunamadı: ' . basename($path));
            }
            // İçerik özeti aynıysa yeniden çözümlemeye gerek yok (aynı istekteki tekrar okumalar)
            $stamp = md5($content);
            if (isset($this->readCache[$table]) && $this->readCache[$table]['stamp'] === $stamp) {
                return $this->readCache[$table]['data'];
            }
            $data = $this->decode($content, $table);
            $this->readCache[$table] = ['stamp' => $stamp, 'data' => $data];

            return $data;
        } finally {
            $this->releaseLock();
        }
    }

    /** @return array{auto_increment: int, rows: list<array<string, mixed>>} */
    private function load(string $table): array
    {
        if (!isset($this->tables[$table])) {
            $this->tables[$table] = $this->readFile($table);
        }

        return $this->tables[$table];
    }

    /** @param array{auto_increment: int, rows: list<array<string, mixed>>} $data */
    private function store(string $table, array $data): void
    {
        $this->tables[$table] = $data;
        $this->dirty[$table] = true;
    }

    private function path(string $table): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . $table . '.json';
    }

    /** @return array{auto_increment: int, rows: list<array<string, mixed>>} */
    private function readFile(string $table): array
    {
        $path = $this->path($table);
        if (!is_file($path)) {
            return ['auto_increment' => 0, 'rows' => []];
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new PersistenceException('Veri dosyası okunamadı: ' . basename($path));
        }

        return $this->decode($content, $table);
    }

    /** @return array{auto_increment: int, rows: list<array<string, mixed>>} */
    private function decode(string $content, string $table): array
    {
        if (trim($content) === '') {
            return ['auto_increment' => 0, 'rows' => []];
        }
        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PersistenceException('Veri dosyası bozuk: ' . $table . '.json');
        }
        if (!is_array($decoded) || !isset($decoded['rows']) || !is_array($decoded['rows'])) {
            throw new PersistenceException('Veri dosyasının biçimi hatalı: ' . $table . '.json');
        }
        /** @var list<array<string, mixed>> $rows */
        $rows = array_values($decoded['rows']);

        return [
            'auto_increment' => is_int($decoded['auto_increment'] ?? null) ? $decoded['auto_increment'] : 0,
            'rows' => $rows,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $candidate
     */
    private function assertUnique(string $table, array $rows, array $candidate, ?int $skipIndex): void
    {
        $def = $this->definition($table);
        $keys = array_merge([$def['pk']], $def['unique']);
        foreach ($keys as $columns) {
            $value = [];
            foreach ($columns as $column) {
                if (($candidate[$column] ?? null) === null) {
                    continue 2; // NULL değerler SQL'deki gibi benzersizliğe takılmaz
                }
                $value[] = (string) $candidate[$column];
            }
            foreach ($rows as $i => $row) {
                if ($i === $skipIndex) {
                    continue;
                }
                $other = [];
                foreach ($columns as $column) {
                    $other[] = (string) ($row[$column] ?? '');
                }
                if ($other === $value) {
                    throw new UniqueConstraintException($table, $columns);
                }
            }
        }
    }

    private function commit(): void
    {
        if ($this->dirty === []) {
            return;
        }
        $moves = [];
        foreach (array_keys($this->dirty) as $table) {
            $json = json_encode($this->tables[$table], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new PersistenceException('Veri JSON biçimine çevrilemedi: ' . $table);
            }
            $target = $this->path($table);
            $tmp = $target . '.tmp-' . bin2hex(random_bytes(4));
            if (file_put_contents($tmp, $json . "\n") !== strlen($json) + 1) {
                @unlink($tmp);
                throw new PersistenceException('Veri dosyası yazılamadı: ' . $table);
            }
            $moves[] = ['tmp' => $tmp, 'target' => $target];
        }

        // Önce günlük yazılır; yeniden adlandırma yarıda kalırsa bir sonraki kilitte tamamlanır.
        $journal = $this->dir . DIRECTORY_SEPARATOR . self::JOURNAL_FILE;
        file_put_contents($journal, json_encode($moves, JSON_UNESCAPED_SLASHES));
        foreach ($moves as $move) {
            $this->renameWithRetry($move['tmp'], $move['target']);
        }
        @unlink($journal);
        $this->readCache = [];
    }

    private function recoverJournal(): void
    {
        $journal = $this->dir . DIRECTORY_SEPARATOR . self::JOURNAL_FILE;
        if (!is_file($journal)) {
            return;
        }
        $moves = json_decode((string) file_get_contents($journal), true);
        if (is_array($moves)) {
            foreach ($moves as $move) {
                if (is_array($move) && is_string($move['tmp'] ?? null) && is_string($move['target'] ?? null) && is_file($move['tmp'])) {
                    $this->renameWithRetry($move['tmp'], $move['target']);
                }
            }
        }
        @unlink($journal);
    }

    /** Paylaşımlı kilitte günlük bulunursa (yarım kalmış yazma) dışlayıcı kilitle tamamlanır. */
    private function recoverJournalIfPresentReadOnly(): void
    {
        if (!is_file($this->dir . DIRECTORY_SEPARATOR . self::JOURNAL_FILE)) {
            return;
        }
        $this->releaseLock();
        $this->acquireLock(LOCK_EX);
        $this->recoverJournal();
        $this->releaseLock();
        $this->acquireLock(LOCK_SH);
    }

    private function renameWithRetry(string $from, string $to): void
    {
        for ($attempt = 0; $attempt < 40; $attempt++) {
            if (@rename($from, $to)) {
                return;
            }
            usleep(25000); // Windows'ta dosya kısa süre başka bir süreçte açık olabilir
        }
        throw new PersistenceException('Veri dosyası değiştirilemedi: ' . basename($to));
    }

    private function acquireLock(int $mode): void
    {
        if ($this->lockHandle === null) {
            $handle = fopen($this->dir . DIRECTORY_SEPARATOR . self::LOCK_FILE, 'c');
            if ($handle === false) {
                throw new PersistenceException('Kilit dosyası açılamadı.');
            }
            $this->lockHandle = $handle;
        }
        $attempts = 1 + max(0, $this->lockRetries);
        $perAttempt = $this->lockTimeoutSeconds / $attempts;
        for ($i = 0; $i < $attempts; $i++) {
            $deadline = microtime(true) + $perAttempt;
            do {
                if (flock($this->lockHandle, $mode | LOCK_NB)) {
                    return;
                }
                usleep(random_int(1000, 5000));
            } while (microtime(true) < $deadline);
        }
        throw new BusyException();
    }

    private function releaseLock(): void
    {
        if ($this->lockHandle !== null) {
            flock($this->lockHandle, LOCK_UN);
        }
    }
}
