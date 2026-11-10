<?php

declare(strict_types=1);

namespace App\Service\Dto;

use App\Model\Amenity;
use App\Model\BookableUnit;
use App\Model\Photo;
use App\Model\Property;
use App\Model\User;

/** İlan detayı (FR-09): açıklama, fotoğraflar, konum, kapasite, olanaklar, fiyat, mod ve iptal koşulu. */
final class PropertyDetail
{
    /**
     * @param list<BookableUnit> $units
     * @param list<Photo> $photos
     * @param list<Amenity> $amenities
     * @param list<array{rating: int, comment: string, author: string, date: \DateTimeImmutable}> $reviews
     * @param array<int, int> $ratingDistribution puan (5'ten 1'e) => yayındaki yorum sayısı
     * @param array<int, int> $unitPhotos oda/birim id => o odanın ilk fotoğrafı (oda tablosundaki küçük görsel)
     * @param list<Photo> $gallery galeri: ilanın kendi fotoğrafları (odaya bağlı olmayanlar; yoksa tümü)
     */
    public function __construct(
        public readonly Property $property,
        public readonly array $units,
        public readonly array $photos,
        public readonly array $amenities,
        public readonly ?float $rating,
        public readonly int $reviewCount,
        public readonly array $reviews,
        public readonly ?User $host,
        public readonly bool $isFavorite,
        public readonly string $policyText,
        public readonly int $minPrice,
        public readonly array $ratingDistribution = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
        public readonly array $unitPhotos = [],
        public readonly array $gallery = [],
    ) {
    }
}
