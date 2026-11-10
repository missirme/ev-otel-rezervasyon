<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ForbiddenException;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Model\BookableUnit;
use App\Model\CalendarEntry;
use App\Model\CalendarStatus;
use App\Model\Property;
use App\Model\UnitStatus;
use App\Repository\BookableUnitRepositoryInterface;
use App\Repository\CalendarRepositoryInterface;
use App\Repository\PropertyRepositoryInterface;
use App\Repository\ReservationRepositoryInterface;
use App\Repository\TransactionManagerInterface;
use App\Service\Dto\MonthCalendar;
use App\Support\ClockInterface;
use App\Support\DateRange;
use App\Support\Dates;
use DateTimeImmutable;

/**
 * Arama ve takvim için müsaitlik. Kuralı kendisi yazmaz; BookingConflictService'i kullanır (SRS 4.7).
 * İlan sahibinin takvim yönetimi (FR-22) de buradadır.
 */
final class AvailabilityService
{
    public function __construct(
        private readonly BookingConflictService $conflicts,
        private readonly BookableUnitRepositoryInterface $units,
        private readonly PropertyRepositoryInterface $properties,
        private readonly CalendarRepositoryInterface $calendar,
        private readonly ReservationRepositoryInterface $reservations,
        private readonly TransactionManagerInterface $tx,
        private readonly AuditService $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * FR-06: aralığın tüm gecelerinde müsait, aktif ve kapasitesi yeten birimler.
     *
     * @return list<BookableUnit>
     */
    public function availableUnits(Property $property, DateRange $range, int $guests): array
    {
        if (!$property->isBookable()) {
            return [];
        }

        return array_values(array_filter(
            $this->units->findByProperty((int) $property->id),
            fn (BookableUnit $u): bool => $u->status === UnitStatus::Aktif
                && $u->capacity >= $guests
                && $this->conflicts->isAvailable((int) $u->id, $range)
        ));
    }

    /** FR-10: ay ileri/geri gezilebilen aylık takvim; dolu ve kapalı geceler ayrı işaretlenir. */
    public function monthCalendar(int $unitId, int $year, int $month): MonthCalendar
    {
        $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $this->clock->today()->getTimezone());
        $nextMonth = $first->modify('first day of next month');
        $statuses = $this->conflicts->nightStatuses($unitId, $first, $nextMonth);
        $exceptions = $this->calendar->findForUnit($unitId, $first, $nextMonth);
        $today = $this->clock->today();

        $weeks = [];
        $week = array_fill(0, (int) $first->format('N') - 1, null);
        for ($day = $first; $day < $nextMonth; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $status = $day < $today ? 'gecmis' : ($statuses[$key] ?? 'musait');
            $week[] = ['date' => $day, 'status' => $status, 'price' => $exceptions[$key]->specialPrice ?? null];
            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }
        }
        if ($week !== []) {
            $weeks[] = array_pad($week, 7, null);
        }

        return new MonthCalendar(
            $unitId,
            $year,
            $month,
            Dates::monthName($month) . ' ' . $year,
            $first->modify('-1 month')->format('Y-m'),
            $nextMonth->format('Y-m'),
            $weeks,
        );
    }

    /**
     * FR-22: ilan sahibi bir tarih aralığını (bitiş dahil) açar/kapatır veya özel fiyat girer.
     * Beklemede/Onaylandı rezervasyonla çakışan geceler kapatılamaz (BR-22, CS-08, T-09).
     */
    public function setCalendarRange(
        int $ownerId,
        int $unitId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        CalendarStatus $status,
        ?int $specialPrice,
    ): int {
        return $this->tx->transactional(function () use ($ownerId, $unitId, $from, $to, $status, $specialPrice): int {
            $unit = $this->units->findById($unitId) ?? throw new NotFoundException('Oda bulunamadı.');
            $property = $this->properties->findById($unit->propertyId) ?? throw new NotFoundException('İlan bulunamadı.');
            if ($property->ownerId !== $ownerId) {
                throw new ForbiddenException('Yalnızca kendi ilanınızın takvimini yönetebilirsiniz.');
            }
            $today = $this->clock->today();
            if ($to < $from) {
                throw ValidationException::single('bitis', 'Bitiş tarihi başlangıçtan önce olamaz.');
            }
            if ($from < $today) {
                throw ValidationException::single('baslangic', 'Geçmiş tarihler için takvim değiştirilemez.');
            }
            if ($to > $today->modify('+365 days')) {
                throw ValidationException::single('bitis', 'En çok 365 gün sonrasına kadar düzenleme yapılabilir.');
            }
            if ($specialPrice !== null && $specialPrice <= 0) {
                throw ValidationException::single('fiyat', 'Özel gecelik fiyat sıfırdan büyük olmalıdır.');
            }

            $range = new DateRange($from, $to->modify('+1 day'));
            $this->reservations->lockUnit($unitId);
            if ($status === CalendarStatus::Kapali) {
                $conflicts = $this->conflicts->conflictingReservations($unitId, $range);
                if ($conflicts !== []) {
                    $info = array_map(
                        fn ($r): string => sprintf('%s (%s, %s)', $r->number, $r->range(), $r->status->label()),
                        $conflicts
                    );
                    throw ValidationException::single(
                        'baslangic',
                        'Bu aralıkta rezervasyon olduğu için geceler kapatılamaz: ' . implode('; ', $info)
                    );
                }
            }

            $count = 0;
            foreach ($range->nightDates() as $night) {
                if ($status === CalendarStatus::Acik && $specialPrice === null) {
                    $this->calendar->deleteForUnitDate($unitId, $night); // varsayılana dön
                } else {
                    $this->calendar->upsert(new CalendarEntry(null, $unitId, $night, $status, $specialPrice));
                }
                $count++;
            }
            $this->audit->log($ownerId, 'unit', $unitId, 'takvim_guncelleme', null, $status->value . ($specialPrice !== null ? ' / özel fiyat' : ''), (string) $range);

            return $count;
        });
    }
}
