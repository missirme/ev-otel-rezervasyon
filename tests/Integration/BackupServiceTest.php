<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exception\PersistenceException;
use App\Exception\ValidationException;
use App\Model\Role;
use App\Model\User;
use App\Seed\DemoSeeder;
use App\Service\BackupService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AppTestCase;

/** NFR-09: günlük yedek, son 7 yedeğin saklanması ve geri yükleme denemesi (her iki depoda). */
#[Group('NFR-09')]
final class BackupServiceTest extends AppTestCase
{
    private function backups(): BackupService
    {
        return $this->c->backupService();
    }

    /** @return array<string, int> */
    private function rowCounts(): array
    {
        return array_map('count', $this->c->dataSnapshot()->exportTables());
    }

    public function testRestoreBringsBackExactlyTheBackedUpData(): void
    {
        (new DemoSeeder($this->c))->run();
        $before = $this->rowCounts();
        $name = $this->backups()->create();

        // Yedekten sonra veri değişir: yeni kullanıcı eklenir, sonra tüm veri silinir
        $this->user(Role::Musteri, 'sonradan@test.local');
        $this->c->resetStorage();
        self::assertSame(0, array_sum($this->rowCounts()));

        $result = $this->backups()->restore($name);

        self::assertSame($before, $result['satirlar']);
        self::assertSame($before, $this->rowCounts(), 'Tüm tablolar yedekteki satır sayısına döndü');
        self::assertNull($this->c->users()->findByEmail('sonradan@test.local'));
        self::assertNotNull($this->c->users()->findByEmail('ali@evotel.test'));
        self::assertSame([], $this->c->consistencyAuditService()->violations(), 'Geri yüklenen veri tutarlı (T-11)');

        // Geri yükleme öncesi durumun yedeği de alınmıştır (yanlış yedek seçilirse geri dönülebilir)
        self::assertContains($result['oncesi'], array_column($this->backups()->list(), 'ad'));
        $new = $this->user(Role::Musteri, 'yeni@test.local');
        self::assertInstanceOf(User::class, $new, 'Geri yüklemeden sonra kimlik sayaçları çakışma üretmez');
    }

    public function testOnlyTheLastSevenBackupsAreKept(): void
    {
        $this->user();
        $names = [];
        for ($i = 0; $i < 9; $i++) {
            $names[] = $this->backups()->create();
            $this->clock->advance('+1 day');
        }

        $kept = array_column($this->backups()->list(), 'ad');
        self::assertCount(BackupService::KEEP, $kept);
        self::assertSame(array_reverse(array_slice($names, 2)), $kept, 'En eski iki yedek silindi, en yeni önce listelenir');
    }

    public function testDailyBackupIsTakenOncePerDay(): void
    {
        $this->user();
        self::assertNotNull($this->backups()->createDailyIfMissing());
        self::assertNull($this->backups()->createDailyIfMissing(), 'Aynı gün ikinci kez alınmaz');
        $this->clock->advance('+1 day');
        self::assertNotNull($this->backups()->createDailyIfMissing());
        self::assertCount(2, $this->backups()->list());
    }

    public function testTamperedBackupIsNotRestored(): void
    {
        $user = $this->user();
        $name = $this->backups()->create();
        $path = $this->dir . '/backups/' . $name;
        file_put_contents($path, str_replace((string) $user->email, 'saldirgan@test.local', (string) file_get_contents($path)));

        try {
            $this->backups()->restore($name);
            self::fail('Özeti tutmayan yedek yüklenmemeli');
        } catch (PersistenceException $e) {
            self::assertStringContainsString('özeti tutmuyor', $e->getMessage());
        }
        self::assertNotNull($this->c->users()->findByEmail((string) $user->email), 'Mevcut veriye dokunulmadı');
    }

    public function testInvalidOrMissingBackupNameIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->backups()->restore('../config/app.php');
    }
}
