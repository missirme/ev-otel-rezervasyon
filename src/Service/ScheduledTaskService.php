<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Logger;

/**
 * Zamanlanmış görev (SRS 2.1, FR-34, FR-40): dakikada bir bin/gorev.php ile çalıştırılır.
 * Doğruluk bu göreve bağlı değildir; çakışma kontrolü süresi dolanları zaten temizler (lazy expiry).
 */
final class ScheduledTaskService
{
    public function __construct(private readonly ReservationService $reservations, private readonly Logger $logger)
    {
    }

    /** @return array{expired: int, completed: int} */
    public function run(): array
    {
        $expired = $this->reservations->expireHolds();
        $completed = $this->reservations->completeStays();
        $this->logger->info('zamanlanmis_gorev', ['suresi_dolan' => $expired, 'tamamlanan' => $completed]);

        return ['expired' => $expired, 'completed' => $completed];
    }
}
