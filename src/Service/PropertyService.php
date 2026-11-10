<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ForbiddenException;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Model\Amenity;
use App\Model\BookableUnit;
use App\Model\Photo;
use App\Model\Property;
use App\Model\PropertyStatus;
use App\Model\PropertyType;
use App\Model\ReservationStatus;
use App\Model\Role;
use App\Model\RoomType;
use App\Model\UnitStatus;
use App\Model\User;
use App\Repository\AmenityRepositoryInterface;
use App\Repository\BookableUnitRepositoryInterface;
use App\Repository\FavoriteRepositoryInterface;
use App\Repository\PhotoRepositoryInterface;
use App\Repository\PropertyRepositoryInterface;
use App\Repository\ReservationRepositoryInterface;
use App\Repository\ReviewRepositoryInterface;
use App\Repository\TransactionManagerInterface;
use App\Repository\UserRepositoryInterface;
use App\Service\Dto\PropertyDetail;
use App\Service\Dto\PropertyInput;
use App\Service\Dto\SearchCriteria;
use App\Service\Dto\SearchResult;
use App\Service\Dto\SearchResultItem;
use App\Support\ClockInterface;
use App\Support\DateRange;
use App\Support\Paginator;
use App\Support\Settings;
use App\Support\Text;
use InvalidArgumentException;

/** İlan arama/görüntüleme (UC-01, FR-06 - FR-09), ilan sahibi ilan/oda yönetimi ve yönetici kaldırma. */
final class PropertyService
{
    public function __construct(
        private readonly PropertyRepositoryInterface $properties,
        private readonly BookableUnitRepositoryInterface $units,
        private readonly AmenityRepositoryInterface $amenities,
        private readonly PhotoRepositoryInterface $photos,
        private readonly ReviewRepositoryInterface $reviews,
        private readonly UserRepositoryInterface $users,
        private readonly FavoriteRepositoryInterface $favorites,
        private readonly ReservationRepositoryInterface $reservations,
        private readonly AvailabilityService $availability,
        private readonly BookingConflictService $conflicts,
        private readonly PricingService $pricing,
        private readonly RefundService $refunds,
        private readonly ReservationService $reservationService,
        private readonly NotificationService $notifications,
        private readonly AuditService $audit,
        private readonly TransactionManagerInterface $tx,
        private readonly ClockInterface $clock,
        private readonly Settings $settings,
    ) {
    }

    /** @return list<Amenity> */
    public function amenities(): array
    {
        return $this->amenities->all();
    }

    /**
     * UC-01: konum, tarih, kişi ve türe göre arama; fiyat/puan/tür/oda tipi/olanak filtreleri;
     * fiyat veya puana göre sıralama; sayfalama.
     */
    public function search(SearchCriteria $criteria): SearchResult
    {
        $errors = [];
        $range = null;
        if ($criteria->hasDates()) {
            try {
                $range = DateRange::fromStrings($criteria->checkIn, $criteria->checkOut, $this->clock->today()->getTimezone());
                $errors = $this->conflicts->dateErrors($range);
            } catch (InvalidArgumentException) {
                $errors['giris'] = 'Giriş ve çıkış tarihlerini birlikte ve geçerli biçimde girin.';
            }
        }
        if ($criteria->guests < 1 || $criteria->guests > 20) {
            $errors['kisi'] = 'Kişi sayısı 1 ile 20 arasında olmalıdır.';
        }
        if ($criteria->minPrice !== null && $criteria->maxPrice !== null && $criteria->minPrice > $criteria->maxPrice) {
            $errors['fiyat'] = 'En düşük fiyat en yüksek fiyattan büyük olamaz.';
        }
        if ($errors !== []) {
            return new SearchResult($criteria, new Paginator([], 1, $this->settings->perPage), $errors, null);
        }

        $items = [];
        foreach ($this->properties->findByStatus(PropertyStatus::Aktif) as $property) {
            if (!Text::contains($property->city . ' ' . $property->address . ' ' . $property->name, $criteria->city)) {
                continue;
            }
            if ($criteria->type !== null && $property->type !== $criteria->type) {
                continue;
            }
            $item = $this->buildItem($property, $range, $criteria);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        usort($items, match ($criteria->sort) {
            'fiyat_azalan' => fn (SearchResultItem $a, SearchResultItem $b): int => $b->nightly <=> $a->nightly,
            'puan' => fn (SearchResultItem $a, SearchResultItem $b): int => [$b->rating ?? 0, $a->nightly] <=> [$a->rating ?? 0, $b->nightly],
            default => fn (SearchResultItem $a, SearchResultItem $b): int => $a->nightly <=> $b->nightly,
        });

        return new SearchResult($criteria, new Paginator($items, $criteria->page, $this->settings->perPage), [], $range);
    }

    /**
     * Ana sayfadaki öne çıkan ilanlar (puana göre).
     *
     * @return list<SearchResultItem>
     */
    public function featured(int $limit = 6, ?int $viewerId = null): array
    {
        $result = $this->search(new SearchCriteria(sort: 'puan', viewerId: $viewerId));

        return array_slice($result->items->items, 0, $limit);
    }

    /**
     * Favoriler sayfası için kartlar.
     *
     * @param list<int> $propertyIds
     * @return list<SearchResultItem>
     */
    public function cards(array $propertyIds, ?int $viewerId = null): array
    {
        $cards = [];
        $criteria = new SearchCriteria(viewerId: $viewerId);
        foreach ($propertyIds as $id) {
            $property = $this->properties->findById($id);
            if ($property === null || $property->status === PropertyStatus::Kaldirildi) {
                continue;
            }
            $item = $this->buildItem($property, null, $criteria, true);
            if ($item !== null) {
                $cards[] = $item;
            }
        }

        return $cards;
    }

    /** FR-09: ilan detayı. Aktif olmayan ilanı yalnızca sahibi ve yönetici görebilir. */
    public function detail(int $propertyId, ?User $viewer): PropertyDetail
    {
        $property = $this->properties->findById($propertyId) ?? throw new NotFoundException('İlan bulunamadı.');
        $isOwnerOrAdmin = $viewer !== null && ($viewer->id === $property->ownerId || $viewer->role === Role::Yonetici);
        if (!$property->isBookable() && !$isOwnerOrAdmin) {
            throw new NotFoundException('Bu ilan şu anda yayında değil.');
        }
        $units = array_values(array_filter(
            $this->units->findByProperty($propertyId),
            fn (BookableUnit $u): bool => $u->status === UnitStatus::Aktif || $isOwnerOrAdmin
        ));
        $amenityIds = array_flip($this->properties->amenityIdsOf($propertyId));
        $amenities = array_values(array_filter($this->amenities->all(), fn (Amenity $a): bool => isset($amenityIds[$a->id])));
        $summary = $this->reviews->ratingSummaries()[$propertyId] ?? null;
        $reviews = [];
        foreach ($this->reviews->findForProperty($propertyId) as $review) {
            $author = $this->users->findById($review->customerId);
            $reviews[] = [
                'rating' => $review->rating,
                'comment' => $review->comment,
                'author' => $author !== null ? $author->firstName . ' ' . mb_substr($author->lastName, 0, 1) . '.' : 'Misafir',
                'date' => $review->createdAt,
            ];
        }
        $prices = array_map(fn (BookableUnit $u): int => $u->basePrice, $units);
        $distribution = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        foreach ($reviews as $review) {
            if (isset($distribution[$review['rating']])) {
                $distribution[$review['rating']]++;
            }
        }
        $photos = $this->photos->findByProperty($propertyId);
        $gallery = array_values(array_filter($photos, fn (Photo $p): bool => $p->unitId === null));
        $unitPhotos = [];
        foreach ($photos as $photo) {
            if ($photo->unitId !== null && $photo->id !== null && !isset($unitPhotos[$photo->unitId])) {
                $unitPhotos[$photo->unitId] = $photo->id;
            }
        }

        return new PropertyDetail(
            $property,
            $units,
            $photos,
            $amenities,
            $summary['avg'] ?? null,
            $summary['count'] ?? 0,
            $reviews,
            $this->users->findById($property->ownerId),
            $viewer !== null && $viewer->id !== null && $this->favorites->exists($viewer->id, $propertyId),
            $this->refunds->policyFor($property->cancellationPolicy)->description(),
            $prices === [] ? 0 : min($prices),
            $distribution,
            $unitPhotos,
            $gallery === [] ? $photos : $gallery,
        );
    }

    /**
     * Birim ve ilanı döndürür; yayında olmayan ilanın birimini yalnızca sahibi ve yönetici görebilir.
     *
     * @return array{0: BookableUnit, 1: Property}
     */
    public function unitWithProperty(int $unitId, ?User $viewer = null): array
    {
        $unit = $this->units->findById($unitId) ?? throw new NotFoundException('Oda bulunamadı.');
        $property = $this->properties->findById($unit->propertyId) ?? throw new NotFoundException('İlan bulunamadı.');
        $isOwnerOrAdmin = $viewer !== null && ($viewer->id === $property->ownerId || $viewer->role === Role::Yonetici);
        if (!$property->isBookable() && !$isOwnerOrAdmin) {
            throw new NotFoundException('Bu ilan şu anda yayında değil.');
        }

        return [$unit, $property];
    }

    // ------------------------------------------------------------- ilan sahibi (FR-20, 21, 38, 42)

    /** @return list<array{property: Property, units: int, photos: int}> */
    public function listForOwner(int $ownerId): array
    {
        $rows = [];
        foreach ($this->properties->findByOwner($ownerId) as $property) {
            if ($property->status === PropertyStatus::Kaldirildi) {
                continue;
            }
            $id = (int) $property->id;
            $rows[] = ['property' => $property, 'units' => count($this->units->findByProperty($id)), 'photos' => count($this->photos->findByProperty($id))];
        }

        return $rows;
    }

    public function getOwned(int $ownerId, int $propertyId): Property
    {
        $property = $this->properties->findById($propertyId) ?? throw new NotFoundException('İlan bulunamadı.');
        if ($property->ownerId !== $ownerId || $property->status === PropertyStatus::Kaldirildi) {
            throw new ForbiddenException('Yalnızca kendi ilanlarınızı yönetebilirsiniz.');
        }

        return $property;
    }

    public function create(int $ownerId, PropertyInput $input): Property
    {
        $this->validateInput($input);

        return $this->tx->transactional(function () use ($ownerId, $input): Property {
            $property = $this->properties->save(new Property(
                null,
                $ownerId,
                trim($input->name),
                $input->type,
                trim($input->description),
                trim($input->address),
                trim($input->city),
                $input->bookingMode,
                $input->cancellationPolicy,
                $input->status,
            ));
            $this->properties->setAmenities((int) $property->id, $input->amenityIds);
            $this->audit->log($ownerId, 'property', (int) $property->id, 'ilan_olusturuldu', null, $property->status->value, null);

            return $property;
        });
    }

    /** Politika değişikliği yalnızca yeni rezervasyonlara uygulanır; mevcutlar anlık görüntüyü korur (AC-28). */
    public function update(int $ownerId, int $propertyId, PropertyInput $input): Property
    {
        $this->validateInput($input);

        return $this->tx->transactional(function () use ($ownerId, $propertyId, $input): Property {
            $property = $this->getOwned($ownerId, $propertyId);
            $old = $property->status->value;
            if ($input->type !== $property->type && $input->type === PropertyType::KiralikEv && count($this->units->findByProperty($propertyId)) > 1) {
                throw ValidationException::single('tur', 'Birden fazla odası olan ilan kiralık eve çevrilemez.');
            }
            $property->name = trim($input->name);
            $property->type = $input->type;
            $property->description = trim($input->description);
            $property->address = trim($input->address);
            $property->city = trim($input->city);
            $property->bookingMode = $input->bookingMode;
            $property->cancellationPolicy = $input->cancellationPolicy;
            $property->status = $input->status;
            $this->properties->save($property);
            $this->properties->setAmenities($propertyId, $input->amenityIds);
            $this->audit->log($ownerId, 'property', $propertyId, 'ilan_guncellendi', $old, $property->status->value, null);

            return $property;
        });
    }

    /** FR-21: mantıksal silme. Gelecekte engelleyen rezervasyonu olan ilan silinemez. */
    public function delete(int $ownerId, int $propertyId): void
    {
        $this->tx->transactional(function () use ($ownerId, $propertyId): void {
            $property = $this->getOwned($ownerId, $propertyId);
            $unitIds = array_map(fn (BookableUnit $u): int => (int) $u->id, $this->units->findByProperty($propertyId));
            $today = $this->clock->today();
            foreach ($this->reservations->findByUnits($unitIds) as $reservation) {
                $active = in_array($reservation->status, [ReservationStatus::Onaylandi, ReservationStatus::Beklemede], true);
                if ($active && $reservation->checkOut > $today && $this->conflicts->isBlocking($reservation)) {
                    throw ValidationException::single(
                        'ilan',
                        'Bu ilanın gelecekte rezervasyonu olduğu için silinemez. Yeni rezervasyonları durdurmak için ilanı pasife alabilirsiniz.'
                    );
                }
            }
            $old = $property->status->value;
            $property->status = PropertyStatus::Kaldirildi;
            $this->properties->save($property);
            $this->audit->log($ownerId, 'property', $propertyId, 'ilan_silindi', $old, PropertyStatus::Kaldirildi->value, 'İlan sahibi sildi');
        });
    }

    /** @return list<BookableUnit> */
    public function unitsOf(int $ownerId, int $propertyId): array
    {
        $this->getOwned($ownerId, $propertyId);

        return $this->units->findByProperty($propertyId);
    }

    /** FR-42: oda/birim ekleme veya düzenleme. Kiralık ev tek birimdir; birim adı ilan içinde benzersizdir. */
    public function saveUnit(
        int $ownerId,
        int $propertyId,
        ?int $unitId,
        string $name,
        RoomType $roomType,
        int $capacity,
        int $basePrice,
        UnitStatus $status,
    ): BookableUnit {
        return $this->tx->transactional(function () use ($ownerId, $propertyId, $unitId, $name, $roomType, $capacity, $basePrice, $status): BookableUnit {
            $property = $this->getOwned($ownerId, $propertyId);
            $existing = $this->units->findByProperty($propertyId);
            $errors = [];
            $name = trim($name);
            if ($name === '' || Text::length($name) > 60) {
                $errors['ad'] = 'Oda adı zorunludur (en çok 60 karakter).';
            }
            foreach ($existing as $other) {
                if ($other->id !== $unitId && Text::lower($other->name) === Text::lower($name)) {
                    $errors['ad'] = 'Bu ilanda aynı adda bir oda zaten var.';
                }
            }
            if ($capacity < 1 || $capacity > 20) {
                $errors['kapasite'] = 'Kapasite 1 ile 20 arasında olmalıdır.';
            }
            if ($basePrice <= 0) {
                $errors['fiyat'] = 'Temel gecelik fiyat sıfırdan büyük olmalıdır.';
            }
            if ($unitId === null && $property->type === PropertyType::KiralikEv && count($existing) >= 1) {
                $errors['ad'] = 'Kiralık ev ilanı yalnızca bir birimden oluşur.';
            }
            if ($errors !== []) {
                throw new ValidationException($errors);
            }
            if ($unitId === null) {
                $unit = $this->units->save(new BookableUnit(null, $propertyId, $name, $roomType, $capacity, $basePrice, $status));
                $this->audit->log($ownerId, 'unit', (int) $unit->id, 'oda_eklendi', null, $name, null);

                return $unit;
            }
            $unit = $this->units->findById($unitId);
            if ($unit === null || $unit->propertyId !== $propertyId) {
                throw new NotFoundException('Oda bulunamadı.');
            }
            $unit->name = $name;
            $unit->roomType = $roomType;
            $unit->capacity = $capacity;
            $unit->basePrice = $basePrice;
            $unit->status = $status;
            $this->audit->log($ownerId, 'unit', $unitId, 'oda_guncellendi', null, $name, null);

            return $this->units->save($unit);
        });
    }

    // ------------------------------------------------------------------ yönetici (FR-28, FR-39)

    /** @return Paginator<Property> */
    public function adminList(string $query, ?PropertyStatus $status, int $page, int $perPage): Paginator
    {
        $list = array_values(array_filter(
            $this->properties->all(),
            fn (Property $p): bool => ($status === null || $p->status === $status) && Text::contains($p->name . ' ' . $p->city, $query)
        ));
        usort($list, fn (Property $a, Property $b): int => (int) $b->id <=> (int) $a->id);

        return new Paginator($list, $page, $perPage);
    }

    public function ownerName(int $ownerId): string
    {
        return $this->users->findById($ownerId)?->fullName() ?? '-';
    }

    /** Kaldırma nedeni zorunludur (BR-10); gelecek rezervasyonlar FR-39 uyarınca iptal edilir. */
    public function removeByAdmin(int $adminId, int $propertyId, string $reason): int
    {
        $reason = trim($reason);
        if (Text::length($reason) < 5 || Text::length($reason) > 500) {
            throw ValidationException::single('neden', 'Kaldırma nedeni 5-500 karakter olmalıdır.');
        }

        return $this->tx->transactional(function () use ($adminId, $propertyId, $reason): int {
            $property = $this->properties->findById($propertyId) ?? throw new NotFoundException('İlan bulunamadı.');
            if ($property->status === PropertyStatus::Kaldirildi) {
                throw ValidationException::single('ilan', 'İlan zaten kaldırılmış.');
            }
            $old = $property->status->value;
            $property->status = PropertyStatus::Kaldirildi; // CS-11: kaldırma sonrası yeni istek reddedilir
            $this->properties->save($property);
            $cancelled = $this->reservationService->cancelFutureReservationsOfProperty($propertyId, $adminId, $reason);
            $this->audit->log($adminId, 'property', $propertyId, 'ilan_kaldirildi', $old, PropertyStatus::Kaldirildi->value, $reason);
            $this->notifications->notify($property->ownerId, 'ilan_kaldirildi', sprintf('"%s" ilanınız yönetici tarafından kaldırıldı. Neden: %s', $property->name, $reason));

            return $cancelled;
        });
    }

    // ------------------------------------------------------------------ yardımcılar

    private function validateInput(PropertyInput $input): void
    {
        $errors = [];
        if (trim($input->name) === '' || Text::length($input->name) > 120) {
            $errors['ad'] = 'İlan adı zorunludur (en çok 120 karakter).';
        }
        if (Text::length(trim($input->description)) < 20 || Text::length($input->description) > 5000) {
            $errors['aciklama'] = 'Açıklama 20-5000 karakter olmalıdır.';
        }
        if (trim($input->address) === '' || Text::length($input->address) > 255) {
            $errors['adres'] = 'Adres zorunludur (en çok 255 karakter).';
        }
        if (trim($input->city) === '' || Text::length($input->city) > 60) {
            $errors['sehir'] = 'Şehir zorunludur.';
        }
        if ($input->status === PropertyStatus::Kaldirildi) {
            $errors['durum'] = 'Geçersiz durum.';
        }
        $validIds = array_map(fn (Amenity $a): ?int => $a->id, $this->amenities->all());
        foreach ($input->amenityIds as $id) {
            if (!in_array($id, $validIds, true)) {
                $errors['olanaklar'] = 'Geçersiz olanak seçimi.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    private function buildItem(Property $property, ?DateRange $range, SearchCriteria $criteria, bool $ignoreFilters = false): ?SearchResultItem
    {
        $propertyId = (int) $property->id;
        $units = array_values(array_filter(
            $this->units->findByProperty($propertyId),
            fn (BookableUnit $u): bool => $u->status === UnitStatus::Aktif
                && $u->capacity >= $criteria->guests
                && ($criteria->roomTypes === [] || in_array($u->roomType, $criteria->roomTypes, true))
        ));
        if ($units === [] && !$ignoreFilters) {
            return null;
        }
        $amenityIds = $this->properties->amenityIdsOf($propertyId);
        if (!$ignoreFilters && array_diff($criteria->amenityIds, $amenityIds) !== []) {
            return null;
        }

        $quote = null;
        $cheapest = null;
        $available = count($units);
        if ($range !== null) {
            // SRS 4.7: arama için müsait birimleri AvailabilityService hesaplar (kuralı BookingConflictService'ten alır);
            // fiyat PricingService'ten gelir (FR-06, FR-44). Oda tipi filtresi bunun üzerine uygulanır.
            $allowed = array_flip(array_map(fn (BookableUnit $u): int => (int) $u->id, $units));
            $availableUnits = array_values(array_filter(
                $this->availability->availableUnits($property, $range, $criteria->guests),
                fn (BookableUnit $u): bool => isset($allowed[(int) $u->id])
            ));
            if ($availableUnits === []) {
                return null;
            }
            $available = count($availableUnits);
            foreach ($availableUnits as $unit) {
                $q = $this->pricing->quote($unit, $range);
                if ($quote === null || $q->total < $quote->total) {
                    $quote = $q;
                    $cheapest = $unit;
                }
            }
            $nightly = $quote->averageNightly();
        } else {
            foreach ($units as $unit) {
                if ($cheapest === null || $unit->basePrice < $cheapest->basePrice) {
                    $cheapest = $unit;
                }
            }
            $nightly = $cheapest->basePrice ?? 0;
        }

        $summary = $this->reviews->ratingSummaries()[$propertyId] ?? null;
        $rating = $summary['avg'] ?? null;
        if (!$ignoreFilters) {
            if ($criteria->minRating !== null && ($rating === null || $rating < $criteria->minRating)) {
                return null;
            }
            if ($criteria->minPrice !== null && $nightly < $criteria->minPrice) {
                return null;
            }
            if ($criteria->maxPrice !== null && $nightly > $criteria->maxPrice) {
                return null;
            }
        }

        $amenityNames = [];
        $set = array_flip($amenityIds);
        foreach ($this->amenities->all() as $amenity) {
            if (isset($set[$amenity->id])) {
                $amenityNames[] = $amenity->name;
            }
        }
        $photos = $this->photos->findByProperty($propertyId);
        $allUnits = $this->units->findByProperty($propertyId);

        return new SearchResultItem(
            $property,
            $cheapest,
            $available,
            $quote,
            $nightly,
            $rating,
            $summary['count'] ?? 0,
            $amenityNames,
            $photos[0]->id ?? null,
            $allUnits === [] ? 0 : max(array_map(fn (BookableUnit $u): int => $u->capacity, $allUnits)),
            $criteria->viewerId !== null && $this->favorites->exists($criteria->viewerId, $propertyId),
        );
    }
}
