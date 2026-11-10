<?php

declare(strict_types=1);

/*
 * Eşzamanlılık testlerinin işçi süreci (T-05, T-06, T-10).
 * Kullanım: php rezervasyon-isci.php <test-dizini> <işlem> <json-parametreler>
 * Tüm işçiler aynı anda başlasın diye verilen zamana kadar bekler.
 * Depo (JSON veri klasörü) test sürecininkiyle aynıdır (TestSettings).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Core\Container;
use App\Exception\BusyException;
use App\Exception\ConflictException;
use App\Exception\UniqueConstraintException;
use App\Model\CancellationPolicy;
use App\Model\Notification;
use App\Model\Reservation;
use App\Model\ReservationStatus;
use App\Service\Dto\CreateReservationRequest;
use App\Support\DateRange;
use App\Support\FrozenClock;
use Tests\Support\TestSettings;

[$script, $testDir, $operation, $json] = $argv + [null, '', '', '{}'];
/** @var array<string, mixed> $params */
$params = json_decode((string) $json, true) ?: [];

$clock = new FrozenClock((string) ($params['now'] ?? '2026-11-01 10:00:00'));
$container = new Container(TestSettings::create((string) $testDir), $clock);

$startAt = (float) ($params['start_at'] ?? 0);
while (microtime(true) < $startAt) {
    usleep(500);
}

try {
    if ($operation === 'rezervasyon') {
        $result = $container->reservationService()->create(new CreateReservationRequest(
            (int) $params['customer'],
            (int) $params['unit'],
            DateRange::fromStrings((string) $params['in'], (string) $params['out']),
            1,
            bin2hex(random_bytes(16)),
            (int) $params['total'],
            'Paralel Test',
            '0532 000 00 00',
            null,
        ));
        echo 'OK ' . $result->reservation->number;
    } elseif ($operation === 'ham-rezervasyon') {
        // SRS 4.8: uygulama kontrolü devre dışı (birim kilidi ve kesişim sorgusu yok); yalnızca depo kısıtı
        // UNIQUE(unit_id, night_date) korur. Rezervasyon, geceleriyle birlikte doğrudan depoya yazılır.
        $range = DateRange::fromStrings((string) $params['in'], (string) $params['out']);
        $now = $clock->now();
        $reservation = $container->tx()->transactional(fn (): Reservation => $container->reservations()->createWithNights(
            new Reservation(
                null,
                'RZ-HAM-' . strtoupper(bin2hex(random_bytes(3))),
                (int) $params['customer'],
                (int) $params['unit'],
                $range->checkIn,
                $range->checkOut,
                1,
                ReservationStatus::Beklemede,
                $now->modify('+10 minutes'),
                100000,
                CancellationPolicy::Orta,
                bin2hex(random_bytes(16)),
                'Ham Test',
                '0532 000 00 00',
                null,
                null,
                null,
                0,
                $now,
                $now,
            ),
            [],
            $range->nightDates(),
        ));
        echo 'OK ' . $reservation->number;
    } elseif ($operation === 'iptal') {
        $container->reservationService()->cancelByCustomer((string) $params['number'], (int) $params['customer'], 'Paralel iptal');
        echo 'OK';
    } elseif ($operation === 'arama') {
        // NFR-01: süre, süreç başlatma hariç yalnızca arama işlemi için ölçülür (sunucu tarafı işlem süresi)
        $started = hrtime(true);
        $result = $container->propertyService()->search(new App\Service\Dto\SearchCriteria(
            city: (string) ($params['city'] ?? ''),
            checkIn: (string) $params['in'],
            checkOut: (string) $params['out'],
            guests: 2,
        ));
        printf('OK %d %.1f', $result->items->total, (hrtime(true) - $started) / 1e6);
    } elseif ($operation === 'yaz') {
        for ($i = 0; $i < (int) $params['count']; $i++) {
            $container->tx()->transactional(fn () => $container->notifications()->save(
                new Notification(null, (int) $params['user'], 'test', 'Süreç ' . getmypid() . ' #' . $i, false, $clock->now())
            ));
        }
        echo 'OK';
    }
} catch (ConflictException) {
    echo 'CONFLICT';
} catch (UniqueConstraintException) {
    echo 'UNIQUE';
} catch (BusyException) {
    echo 'BUSY';
} catch (Throwable $e) {
    echo 'ERROR ' . $e::class . ': ' . $e->getMessage();
}
