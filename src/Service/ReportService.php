<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ValidationException;
use App\Model\BookableUnit;
use App\Model\Property;
use App\Model\PropertyStatus;
use App\Model\ReservationRevenue;
use App\Model\ReservationStatus;
use App\Model\RoomType;
use App\Model\UnitStatus;
use App\Repository\BookableUnitRepositoryInterface;
use App\Repository\PropertyRepositoryInterface;
use App\Repository\ReservationRepositoryInterface;
use App\Service\Dto\Report;
use App\Service\Dto\ReportRow;
use App\Support\DateRange;
use App\Support\Dates;
use App\Support\Money;
use DateTimeImmutable;

/**
 * Doluluk ve gelir raporu (FR-30). Formüller (BR-30):
 *  Doluluk (%) = Onaylandı + Tamamlandı rezervasyonların dönemle kesişen gece sayısı
 *                ÷ (aktif birim sayısı × dönemdeki gece sayısı) × 100
 *  Net gelir   = giriş tarihi dönemde olan rezervasyonların başarılı ödemeleri − iadeler
 * Dönem başlangıç ve bitiş günü dahildir. Yönetici tüm ilanları, ilan sahibi yalnızca kendi ilanlarını görür.
 *
 * Rezervasyon ve ödeme verisi tek bir JOIN'li rapor sorgusundan gelir (ReservationRepositoryInterface::reportRows:
 * reservations ⋈ bookable_units ⋈ properties ⟕ payments); kırılımlar bu satırlar üzerinden hesaplanır.
 */
final class ReportService
{
    public function __construct(
        private readonly PropertyRepositoryInterface $properties,
        private readonly BookableUnitRepositoryInterface $units,
        private readonly ReservationRepositoryInterface $reservations,
    ) {
    }

    /** $ownerId null ise tüm ilanlar (yönetici). */
    public function build(?int $ownerId, DateTimeImmutable $from, DateTimeImmutable $to): Report
    {
        if ($to < $from) {
            throw ValidationException::single('bitis', 'Bitiş tarihi başlangıçtan önce olamaz.');
        }
        if ($from->diff($to)->days > 731) {
            throw ValidationException::single('bitis', 'Rapor dönemi en çok 2 yıl olabilir.');
        }
        $period = new DateRange($from, $to->modify('+1 day'));

        $properties = $ownerId === null ? $this->properties->all() : $this->properties->findByOwner($ownerId);
        $propertyById = [];
        foreach ($properties as $property) {
            $propertyById[(int) $property->id] = $property;
        }
        /** @var list<BookableUnit> $units */
        $units = array_values(array_filter($this->units->all(), fn (BookableUnit $u): bool => isset($propertyById[$u->propertyId])));
        $reservations = $this->reservations->reportRows($ownerId, $period); // JOIN'li rapor sorgusu

        $build = function (string $label, array $groupUnits, DateRange $range) use ($reservations, $propertyById): ReportRow {
            return $this->row($label, $groupUnits, $range, $reservations, $propertyById);
        };

        $byProperty = [];
        foreach ($properties as $property) {
            $byProperty[] = $build($property->name, array_values(array_filter($units, fn (BookableUnit $u): bool => $u->propertyId === $property->id)), $period);
        }
        $byUnit = [];
        foreach ($units as $unit) {
            $byUnit[] = $build(($propertyById[$unit->propertyId]->name ?? '') . ' / ' . $unit->name, [$unit], $period);
        }
        $byRoomType = [];
        foreach (RoomType::cases() as $type) {
            $group = array_values(array_filter($units, fn (BookableUnit $u): bool => $u->roomType === $type));
            if ($group !== []) {
                $byRoomType[] = $build($type->label(), $group, $period);
            }
        }
        $byMonth = [];
        for ($month = $from->modify('first day of this month'); $month <= $to; $month = $month->modify('first day of next month')) {
            $start = max($from, $month);
            $end = min($to->modify('+1 day'), $month->modify('first day of next month'));
            $byMonth[] = $build(Dates::monthName((int) $month->format('n')) . ' ' . $month->format('Y'), $units, new DateRange($start, $end));
        }

        $statusCounts = [];
        foreach (ReservationStatus::cases() as $status) {
            $statusCounts[$status->label()] = 0;
        }
        foreach ($reservations as $reservation) {
            if ($this->checkInWithin($reservation, $period)) {
                $statusCounts[$reservation->status->label()]++;
            }
        }

        return new Report($from, $to, $period->nights(), $build('Toplam', $units, $period), $statusCounts, $byProperty, $byUnit, $byRoomType, $byMonth);
    }

    /**
     * Raporu CSV satırlarına çevirir (bonus: CSV dışa aktarma).
     *
     * @return list<list<string>>
     */
    public function toCsvRows(Report $report): array
    {
        $rows = [['Kırılım', 'Ad', 'Aktif birim', 'Kapasite (gece)', 'Dolu gece', 'Doluluk (%)', 'Rezervasyon', 'Brüt ödeme (TL)', 'İade (TL)', 'Net gelir (TL)']];
        $sections = ['Toplam' => [$report->total], 'İlan' => $report->byProperty, 'Birim' => $report->byUnit, 'Oda tipi' => $report->byRoomType, 'Ay' => $report->byMonth];
        foreach ($sections as $section => $list) {
            foreach ($list as $row) {
                $rows[] = [
                    $section,
                    $row->label,
                    (string) $row->activeUnits,
                    (string) $row->capacityNights,
                    (string) $row->occupiedNights,
                    number_format($row->occupancyBp / 100, 2, ',', ''),
                    (string) $row->reservationCount,
                    number_format($row->grossRevenue / 100, 2, ',', ''),
                    number_format($row->refunds / 100, 2, ',', ''),
                    number_format($row->netRevenue / 100, 2, ',', ''),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param list<BookableUnit> $groupUnits
     * @param list<ReservationRevenue> $reservations
     * @param array<int, Property> $propertyById
     */
    private function row(string $label, array $groupUnits, DateRange $range, array $reservations, array $propertyById): ReportRow
    {
        $groupIds = [];
        $active = 0;
        foreach ($groupUnits as $unit) {
            $groupIds[(int) $unit->id] = true;
            $propertyStatus = $propertyById[$unit->propertyId]->status ?? PropertyStatus::Kaldirildi;
            if ($unit->status === UnitStatus::Aktif && in_array($propertyStatus, [PropertyStatus::Aktif, PropertyStatus::Pasif], true)) {
                $active++;
            }
        }
        $capacity = $active * $range->nights();
        $occupied = 0;
        $count = 0;
        $gross = 0;
        $refunds = 0;
        foreach ($reservations as $reservation) {
            if (!isset($groupIds[$reservation->unitId])) {
                continue;
            }
            if (in_array($reservation->status, [ReservationStatus::Onaylandi, ReservationStatus::Tamamlandi], true)) {
                $occupied += $reservation->range()->overlapNights($range);
            }
            if ($this->checkInWithin($reservation, $range)) {
                $count++;
                $gross += $reservation->paid;
                $refunds += $reservation->refunded;
            }
        }
        $occupancy = $capacity > 0 ? Money::roundDiv($occupied * 10000, $capacity) : 0;

        return new ReportRow($label, $active, $capacity, $occupied, $occupancy, $count, $gross, $refunds, $gross - $refunds);
    }

    private function checkInWithin(ReservationRevenue $reservation, DateRange $range): bool
    {
        return $reservation->checkIn >= $range->checkIn && $reservation->checkIn < $range->checkOut;
    }
}
