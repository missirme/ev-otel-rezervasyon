<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ConflictException;
use App\Exception\InvalidTransitionException;
use App\Exception\PersistenceException;
use App\Model\Reservation;
use App\Model\ReservationStatus;
use App\Repository\ReservationRepositoryInterface;
use App\Service\State\ReservationState;
use App\Support\ClockInterface;
use App\Support\Logger;

/**
 * Rezervasyon durum geçişlerinin tek uygulandığı yer (FR-15, SRS 4.3).
 * İşlem (transaction) içinde çağrılmalıdır.
 */
final class ReservationStateMachine
{
    public function __construct(
        private readonly ReservationRepositoryInterface $reservations,
        private readonly AuditService $audit,
        private readonly ClockInterface $clock,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $changes geçişle birlikte yazılacak alanlar (ör. cancel_reason)
     */
    public function transition(
        Reservation $reservation,
        ReservationStatus $to,
        ?int $actorId,
        string $reason,
        array $changes = [],
    ): Reservation {
        $id = $reservation->id ?? throw new PersistenceException('Kaydedilmemiş rezervasyonun durumu değiştirilemez.');
        $from = ReservationState::of($reservation->status);

        if (!$from->canTransitionTo($to)) {
            $this->logger->warning('gecersiz_durum_gecisi', ['rezervasyon' => $id, 'eski' => $reservation->status->value, 'yeni' => $to->value]);
            throw new InvalidTransitionException($id, $reservation->status, $to, $actorId);
        }

        // Koşullu güncelleme: durum bu arada başka bir işlemle değiştiyse etkilenen satır 0 olur.
        $changes['updated_at'] = $this->clock->now();
        if (!$this->reservations->updateStatusIf($id, $reservation->status, $to, $changes)) {
            throw new ConflictException('Rezervasyonun durumu başka bir işlemle değişti. Lütfen sayfayı yenileyip tekrar deneyin.');
        }

        // Engellemeyen duruma geçildiyse geceler aynı işlemde serbest bırakılır.
        if (!ReservationState::of($to)->holdsNights()) {
            $this->reservations->releaseNights($id);
        }

        $this->audit->log($actorId, 'reservation', $id, 'durum_gecisi', $reservation->status->value, $to->value, $reason);
        $this->logger->info('durum_gecisi', ['rezervasyon' => $id, 'eski' => $reservation->status->value, 'yeni' => $to->value]);

        return $this->reservations->findById($id) ?? throw new PersistenceException('Rezervasyon okunamadı.');
    }
}
