<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Model\BookableUnit;
use App\Model\ReservationStatus;
use App\Model\Role;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AppTestCase;

/**
 * Gerçek paralel süreçlerle eşzamanlılık (SRS 4.5). Aşama 2'de JSON deposunun dosya kilidi (flock) sınanır:
 *  T-05: 50 paralel istek, aynı birim ve aralık -> tam 1 başarı, 49 çakışma, gece sayısı = gece sayısı
 *  T-06: kısmen kesişen istekler -> tek başarı; arka arkaya istekler -> iki başarı
 *  T-10: iki süreç aynı tabloya eşzamanlı yazar -> veri bozulmaz, yazma kaybolmaz, çift kimlik olmaz
 */
#[Group('eszamanlilik')]
final class ParallelReservationTest extends AppTestCase
{
    private int $customerId;
    private BookableUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $host = $this->user(Role::IlanSahibi);
        $this->customerId = (int) $this->user()->id;
        $this->unit = $this->unit((int) $this->property((int) $host->id)->id, 1000, 2);
    }

    /**
     * @param list<array<string, mixed>> $jobs
     * @return list<string> her sürecin çıktısı
     */
    private function runParallel(string $operation, array $jobs): array
    {
        $worker = dirname(__DIR__) . '/bin/rezervasyon-isci.php';
        $startAt = microtime(true) + 1.5 + count($jobs) * 0.03; // tüm süreçler başlasın, sonra aynı anda yarışsınlar
        $processes = [];
        foreach ($jobs as $i => $params) {
            $params['start_at'] = $startAt;
            $params['now'] = '2026-11-01 10:00:00';
            $command = [PHP_BINARY, $worker, $this->dir, $operation, (string) json_encode($params)];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $processes[$i] = [$process, $pipes];
        }
        $outputs = [];
        foreach ($processes as [$process, $pipes]) {
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $outputs[] = trim($out . ($err !== '' ? ' STDERR: ' . $err : ''));
        }

        return $outputs;
    }

    private function total(string $in, string $out): int
    {
        return $this->c->pricingService()->quote($this->unit, $this->range($in, $out))->total;
    }

    #[Group('T-05')]
    #[Group('CS-01')]
    #[Group('AC-05')]
    #[Group('AC-13')]
    #[Group('FR-18')]
    #[Group('FR-33')]
    #[Group('NFR-05')]
    public function testT05FiftyParallelRequestsCreateExactlyOneReservation(): void
    {
        $job = ['customer' => $this->customerId, 'unit' => $this->unit->id, 'in' => '2026-11-10', 'out' => '2026-11-13', 'total' => $this->total('2026-11-10', '2026-11-13')];
        $outputs = $this->runParallel('rezervasyon', array_fill(0, 50, $job));

        $ok = array_filter($outputs, fn (string $o): bool => str_starts_with($o, 'OK'));
        $conflicts = array_filter($outputs, fn (string $o): bool => $o === 'CONFLICT');
        self::assertCount(1, $ok, implode("\n", array_unique($outputs)));
        self::assertCount(49, $conflicts, implode("\n", array_unique($outputs)));

        $reservations = $this->c->reservations()->all();
        self::assertCount(1, $reservations);
        self::assertSame(ReservationStatus::Beklemede, $reservations[0]->status);
        self::assertCount(3, $this->c->reservations()->allNights(), 'Gece kaydı sayısı = gece sayısı (AC-13)');
        self::assertSame([], $this->c->consistencyAuditService()->violations(), 'T-11');
    }

    /**
     * SRS 4.8: eşzamanlılık testi uygulama kontrolü (birim kilidi + kesişim sorgusu) devre dışı bırakılarak da
     * çalıştırılır. 50 süreç aynı geceleri doğrudan depoya yazar; yalnızca UNIQUE(unit_id, night_date) kısıtı korur.
     */
    #[Group('T-05')]
    #[Group('NFR-05')]
    #[Group('FR-18')]
    #[Group('AC-05')]
    public function testT05bWithAppChecksDisabledTheUniqueConstraintAloneAllowsOnlyOne(): void
    {
        $job = ['customer' => $this->customerId, 'unit' => $this->unit->id, 'in' => '2026-11-10', 'out' => '2026-11-13'];
        $outputs = $this->runParallel('ham-rezervasyon', array_fill(0, 50, $job));

        self::assertCount(1, array_filter($outputs, fn (string $o): bool => str_starts_with($o, 'OK')), implode("
", array_unique($outputs)));
        self::assertCount(49, array_filter($outputs, fn (string $o): bool => $o === 'UNIQUE'), implode("
", array_unique($outputs)));
        self::assertCount(1, $this->c->reservations()->all(), 'Geri alınan işlemlerden rezervasyon satırı kalmadı');
        self::assertCount(3, $this->c->reservations()->allNights());
        self::assertSame([], $this->c->consistencyAuditService()->violations(), 'T-11');
    }

    #[Group('T-06')]
    #[Group('CS-02')]
    #[Group('CS-03')]
    #[Group('AC-14')]
    #[Group('BR-13')]
    #[Group('FR-18')]
    #[Group('NFR-05')]
    public function testT06PartialOverlapOneWinsBackToBackBothWin(): void
    {
        $partial = $this->runParallel('rezervasyon', [
            ['customer' => $this->customerId, 'unit' => $this->unit->id, 'in' => '2026-11-10', 'out' => '2026-11-12', 'total' => $this->total('2026-11-10', '2026-11-12')],
            ['customer' => $this->customerId, 'unit' => $this->unit->id, 'in' => '2026-11-11', 'out' => '2026-11-13', 'total' => $this->total('2026-11-11', '2026-11-13')],
        ]);
        self::assertCount(1, array_filter($partial, fn (string $o): bool => str_starts_with($o, 'OK')), implode("\n", $partial));

        $backToBack = $this->runParallel('rezervasyon', [
            ['customer' => $this->customerId, 'unit' => $this->unit->id, 'in' => '2026-11-20', 'out' => '2026-11-22', 'total' => $this->total('2026-11-20', '2026-11-22')],
            ['customer' => $this->customerId, 'unit' => $this->unit->id, 'in' => '2026-11-22', 'out' => '2026-11-24', 'total' => $this->total('2026-11-22', '2026-11-24')],
        ]);
        self::assertCount(2, array_filter($backToBack, fn (string $o): bool => str_starts_with($o, 'OK')), implode("\n", $backToBack));
        self::assertSame([], $this->c->consistencyAuditService()->violations());
    }

    /** CS-07: rezervasyon iptal edilirken başka müşteri aynı geceleri ister. */
    #[Group('CS-07')]
    #[Group('AC-16')]
    #[Group('NFR-05')]
    public function testCs07CancellationRacingWithNewRequestsNeverDoubleBooks(): void
    {
        $first = $this->bookAndPay($this->customerId, $this->unit, '2026-11-10', '2026-11-12');
        $other = (int) $this->user()->id;
        $jobs = [['op' => 'iptal', 'number' => $first->number, 'customer' => $this->customerId]];
        for ($i = 0; $i < 10; $i++) {
            $jobs[] = [
                'op' => 'rezervasyon',
                'customer' => $other,
                'unit' => $this->unit->id,
                'in' => '2026-11-10',
                'out' => '2026-11-12',
                'total' => $this->total('2026-11-10', '2026-11-12'),
            ];
        }
        $outputs = $this->runMixed($jobs);

        self::assertSame('OK', $outputs[0], 'İptal başarılı');
        $won = array_filter(array_slice($outputs, 1), fn (string $o): bool => str_starts_with($o, 'OK'));
        self::assertLessThanOrEqual(1, count($won), 'Serbest kalan geceleri en fazla bir istek alır: ' . implode(', ', $outputs));
        self::assertSame(count($jobs) - 1 - count($won), count(array_filter(array_slice($outputs, 1), fn (string $o): bool => $o === 'CONFLICT')));
        self::assertSame(ReservationStatus::IptalEdildi, $this->c->reservations()->findById((int) $first->id)?->status);
        self::assertSame([], $this->c->consistencyAuditService()->violations(), 'T-11');
    }

    /**
     * NFR-01 (sunucu tarafı ölçüm): örnek veride 50 eşzamanlı arama isteğinin yüzde 95'i 3 saniyenin altında tamamlanır.
     * Ölçüm, tarayıcı/ağ süresi olmadan yalnızca arama işlemini kapsar.
     */
    #[Group('NFR-01')]
    #[Group('NFR-11')]
    public function testNfr01FiftyConcurrentSearchesStayUnderThreeSecondsAtP95(): void
    {
        (new \App\Seed\DemoSeeder($this->c))->run();
        $job = ['in' => '2026-11-20', 'out' => '2026-11-23'];
        $outputs = $this->runParallel('arama', array_fill(0, 50, $job));

        $times = [];
        foreach ($outputs as $output) {
            self::assertMatchesRegularExpression('/^OK \d+ [\d.]+$/', $output);
            [, $count, $ms] = explode(' ', $output);
            self::assertGreaterThan(0, (int) $count, 'Aramada müsait ilan bulundu');
            $times[] = (float) $ms;
        }
        sort($times);
        $p95 = $times[(int) ceil(0.95 * count($times)) - 1];
        self::assertLessThan(3000.0, $p95, sprintf('p95 = %.0f ms', $p95));
    }

    /**
     * Farklı işlemleri aynı anda başlatır (her işin 'op' alanı işçinin işlemidir).
     *
     * @param list<array<string, mixed>> $jobs
     * @return list<string>
     */
    private function runMixed(array $jobs): array
    {
        $outputs = [];
        $byOp = [];
        foreach ($jobs as $i => $job) {
            $byOp[(string) $job['op']][$i] = $job;
        }
        // Tüm süreçler aynı başlangıç anını paylaşsın diye tek seferde başlatılır
        $worker = dirname(__DIR__) . '/bin/rezervasyon-isci.php';
        $startAt = microtime(true) + 1.5 + count($jobs) * 0.03;
        $processes = [];
        foreach ($byOp as $op => $list) {
            foreach ($list as $i => $params) {
                $params['start_at'] = $startAt;
                $params['now'] = $this->clock->now()->format('Y-m-d H:i:s');
                $process = proc_open([PHP_BINARY, $worker, $this->dir, $op, (string) json_encode($params)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                $processes[$i] = [$process, $pipes];
            }
        }
        ksort($processes);
        foreach ($processes as $i => [$process, $pipes]) {
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $outputs[$i] = trim($out . ($err !== '' ? ' STDERR: ' . $err : ''));
        }

        return array_values($outputs);
    }

    #[Group('T-10')]
    public function testT10ConcurrentWritesDoNotCorruptStorage(): void
    {
        $outputs = $this->runParallel('yaz', [
            ['user' => $this->customerId, 'count' => 40],
            ['user' => $this->customerId, 'count' => 40],
        ]);
        self::assertSame(['OK', 'OK'], $outputs);

        self::assertJson($this->rawTable('notifications'), 'JSON dosyası bozulmadı');
        $rows = $this->c->notifications()->findByUser($this->customerId);
        self::assertCount(80, $rows, 'Hiçbir yazma kaybolmadı');
        $ids = array_map(fn (\App\Model\Notification $n): ?int => $n->id, $rows);
        self::assertSame($ids, array_unique($ids), 'Çift kimlik yok');
    }
}
