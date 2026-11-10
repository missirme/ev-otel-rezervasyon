<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Reservation;
use App\Model\ReservationStatus;
use App\Repository\ReservationRepositoryInterface;
use App\Service\State\ReservationState;

/**
 * T-11 / NFR-18 tutarlılık denetimi: sağlıklı veride boş liste döner.
 *  1. Aynı birimde gecesi kesişen iki engelleyen rezervasyon var mı? (çift rezervasyon)
 *  2. Geceleri tutan her rezervasyonun gece kaydı sayısı = çıkış − giriş mi?
 *  3. Engellemeyen durumdaki rezervasyonun gece kaydı kalmış mı?
 *  4. Her gece kaydı var olan rezervasyona ve aynı birime mi ait?
 */
final class ConsistencyAuditService
{
    public function __construct(
        private readonly ReservationRepositoryInterface $reservations,
        private readonly BookingConflictService $conflicts,
    ) {
    }

    /** @return list<string> */
    public function violations(): array
    {
        $problems = [];
        $all = $this->reservations->all();
        $byId = [];
        $blockingByUnit = [];
        foreach ($all as $reservation) {
            $byId[(int) $reservation->id] = $reservation;
            if ($this->conflicts->isBlocking($reservation)) {
                $blockingByUnit[$reservation->unitId][] = $reservation;
            }
        }

        foreach ($blockingByUnit as $unitId => $list) {
            $count = count($list);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($list[$i]->range()->overlaps($list[$j]->range())) {
                        $problems[] = sprintf('Birim %d: %s ve %s aynı geceleri tutuyor.', $unitId, $list[$i]->number, $list[$j]->number);
                    }
                }
            }
        }

        $nightsByReservation = [];
        $seen = [];
        foreach ($this->reservations->allNights() as $night) {
            $key = $night->unitId . '|' . $night->nightDate->format('Y-m-d');
            if (isset($seen[$key])) {
                $problems[] = sprintf('Birim %d, %s gecesi iki kez tutulmuş.', $night->unitId, $night->nightDate->format('d.m.Y'));
            }
            $seen[$key] = true;
            $owner = $byId[$night->reservationId] ?? null;
            if (!$owner instanceof Reservation) {
                $problems[] = sprintf('Gece kaydı var olmayan rezervasyona bağlı (id %d).', $night->reservationId);
                continue;
            }
            if ($owner->unitId !== $night->unitId || !$owner->range()->containsNight($night->nightDate)) {
                $problems[] = sprintf('%s rezervasyonunun gece kaydı birim veya tarih aralığıyla uyuşmuyor.', $owner->number);
            }
            $nightsByReservation[$night->reservationId] = ($nightsByReservation[$night->reservationId] ?? 0) + 1;
        }

        foreach ($all as $reservation) {
            $stored = $nightsByReservation[(int) $reservation->id] ?? 0;
            $holds = ReservationState::of($reservation->status)->holdsNights();
            if ($holds && $stored !== $reservation->nights()) {
                $problems[] = sprintf('%s: %d gece kaydı olmalı, %d var.', $reservation->number, $reservation->nights(), $stored);
            }
            if (!$holds && $stored > 0 && $reservation->status !== ReservationStatus::Beklemede) {
                $problems[] = sprintf('%s (%s) için gece kaydı kalmış.', $reservation->number, $reservation->status->label());
            }
        }

        return $problems;
    }
}
