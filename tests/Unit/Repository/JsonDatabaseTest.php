<?php

declare(strict_types=1);

namespace Tests\Unit\Repository;

use App\Exception\PersistenceException;
use App\Exception\UniqueConstraintException;
use App\Repository\Json\JsonDatabase;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\AppTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Aşama 2 JSON deposu: benzersizlik (son savunma), geri alma, koşullu güncelleme, günlükle kurtarma. */
final class JsonDatabaseTest extends TestCase
{
    private string $dir;
    private JsonDatabase $db;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/evotel-db-' . bin2hex(random_bytes(5));
        $this->db = new JsonDatabase($this->dir);
    }

    protected function tearDown(): void
    {
        AppTestCase::removeDirectory($this->dir);
    }

    #[Group('FR-18')]
    #[Group('NFR-05')]
    public function testUniqueUnitNightIsEnforced(): void
    {
        $this->db->insert('reservation_nights', ['reservation_id' => 1, 'unit_id' => 7, 'night_date' => '2026-11-10']);

        $this->expectException(UniqueConstraintException::class);
        $this->db->insert('reservation_nights', ['reservation_id' => 2, 'unit_id' => 7, 'night_date' => '2026-11-10']);
    }

    #[Group('FR-33')]
    public function testExceptionRollsBackTheWholeTransaction(): void
    {
        try {
            $this->db->transaction(function (): void {
                $this->db->insert('reservations', ['reservation_no' => 'RZ-1', 'idempotency_key' => 'a', 'unit_id' => 1, 'status' => 'beklemede']);
                $this->db->insert('reservation_nights', ['reservation_id' => 1, 'unit_id' => 1, 'night_date' => '2026-11-10']);
                throw new RuntimeException('gece yazılırken hata');
            });
        } catch (RuntimeException) {
        }

        self::assertSame([], $this->db->select('reservations'));
        self::assertSame([], $this->db->select('reservation_nights'));
        self::assertFileDoesNotExist($this->dir . '/reservations.json', 'Hata olunca hiçbir dosya yazılmaz');
    }

    #[Group('FR-15')]
    public function testConditionalUpdateReportsAffectedRows(): void
    {
        $row = $this->db->insert('reservations', ['reservation_no' => 'RZ-1', 'idempotency_key' => 'a', 'status' => 'beklemede']);

        $stillPending = fn ($r) => $r['id'] === $row['id'] && $r['status'] === 'beklemede';

        self::assertSame(1, $this->db->update('reservations', $stillPending, ['status' => 'onaylandi']));
        self::assertSame(0, $this->db->update('reservations', $stillPending, ['status' => 'iptal_edildi']), 'Durum değiştiyse etkilenen satır 0');
        self::assertSame('onaylandi', $this->db->first('reservations', fn ($r) => true)['status'] ?? null);
    }

    public function testAutoIncrementAndPersistence(): void
    {
        $a = $this->db->insert('amenities', ['name' => 'Wi-Fi']);
        $b = $this->db->insert('amenities', ['name' => 'Havuz']);
        $fresh = new JsonDatabase($this->dir);

        self::assertSame([1, 2], [$a['id'], $b['id']]);
        self::assertCount(2, $fresh->select('amenities'));
        self::assertJson((string) file_get_contents($this->dir . '/amenities.json'));
    }

    #[Group('CS-12')]
    #[Group('FR-33')]
    public function testInterruptedCommitIsCompletedFromJournal(): void
    {
        // Yarıda kalmış bir yazma: geçici dosya ve günlük var, yeniden adlandırma yapılmamış
        $tmp = $this->dir . '/amenities.json.tmp-test';
        file_put_contents($tmp, json_encode(['auto_increment' => 1, 'rows' => [['id' => 1, 'name' => 'Spa']]]));
        file_put_contents($this->dir . '/.journal.json', json_encode([['tmp' => $tmp, 'target' => $this->dir . '/amenities.json']]));

        $this->db->transaction(fn () => null);

        self::assertSame('Spa', $this->db->select('amenities')[0]['name']);
        self::assertFileDoesNotExist($this->dir . '/.journal.json');
    }

    #[Group('NFR-10')]
    public function testCorruptedFileIsReportedNotSilentlyIgnored(): void
    {
        file_put_contents($this->dir . '/users.json', '{bozuk');

        $this->expectException(PersistenceException::class);
        $this->db->select('users');
    }
}
