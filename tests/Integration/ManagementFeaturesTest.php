<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exception\ForbiddenException;
use App\Exception\ValidationException;
use App\Model\Amenity;
use App\Model\AuditLog;
use App\Model\BookingMode;
use App\Model\CancellationPolicy;
use App\Model\ComplaintStatus;
use App\Model\ModerationStatus;
use App\Model\PropertyStatus;
use App\Model\PropertyType;
use App\Model\ReservationStatus;
use App\Model\Role;
use App\Model\RoomType;
use App\Model\UnitStatus;
use App\Model\UserStatus;
use App\Service\Dto\PropertyInput;
use App\Service\Dto\SearchCriteria;
use App\Service\Dto\SearchResultItem;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AppTestCase;

/**
 * Çekirdek dışı ama zorunlu/gerekli işlevler: ilan ve oda yönetimi, arama filtreleri ve sıralama,
 * favoriler, yorum moderasyonu, şikâyet, bildirim, hesap kısıtlama ve profil (UC-03, FR-07 ... FR-42).
 */
final class ManagementFeaturesTest extends AppTestCase
{
    /** @param list<int> $amenityIds */
    private function input(string $name, BookingMode $mode = BookingMode::Aninda, CancellationPolicy $policy = CancellationPolicy::Orta, array $amenityIds = []): PropertyInput
    {
        return new PropertyInput(
            $name,
            PropertyType::Otel,
            'Denize yakın, sessiz ve temiz bir konaklama yeri.',
            'Sahil Cad. No: 1',
            'Rize',
            $mode,
            $policy,
            PropertyStatus::Aktif,
            $amenityIds,
        );
    }

    /**
     * @param list<SearchResultItem> $items
     * @return list<string>
     */
    private function names(array $items): array
    {
        return array_map(fn (SearchResultItem $i): string => $i->property->name, $items);
    }

    #[Group('FR-20')]
    #[Group('FR-38')]
    #[Group('FR-42')]
    #[Group('FR-09')]
    #[Group('BR-06')]
    #[Group('UC-03')]
    public function testHostCreatesPropertyWithUnitsBookingModeAndPolicy(): void
    {
        $host = $this->user(Role::IlanSahibi);
        $wifi = $this->c->amenities()->save(new Amenity(null, 'Wi-Fi'));
        $properties = $this->c->propertyService();

        $property = $properties->create((int) $host->id, $this->input('Rize Butik Otel', BookingMode::Onayli, CancellationPolicy::Kati, [(int) $wifi->id]));
        $properties->saveUnit((int) $host->id, (int) $property->id, null, '101', RoomType::CiftKisilik, 2, 150000, UnitStatus::Aktif);
        $properties->saveUnit((int) $host->id, (int) $property->id, null, '102', RoomType::Aile, 4, 220000, UnitStatus::Aktif);

        try {
            $properties->saveUnit((int) $host->id, (int) $property->id, null, '101', RoomType::Suit, 0, 0, UnitStatus::Aktif);
            self::fail('Aynı adlı, kapasitesiz ve fiyatsız oda reddedilmeli');
        } catch (ValidationException $e) {
            self::assertSame(['ad', 'kapasite', 'fiyat'], array_keys($e->errors()));
        }

        $detail = $properties->detail((int) $property->id, null);
        self::assertSame(BookingMode::Onayli, $detail->property->bookingMode);
        self::assertSame(CancellationPolicy::Kati, $detail->property->cancellationPolicy);
        self::assertCount(2, $detail->units);
        self::assertSame(['Wi-Fi'], array_map(fn (Amenity $a): string => $a->name, $detail->amenities));
        self::assertStringContainsString('Katı', $detail->policyText, 'BR-06: iptal koşulu rezervasyondan önce gösterilir');
        self::assertSame(150000, $detail->minPrice);
    }

    #[Group('FR-21')]
    #[Group('BR-08')]
    #[Group('AC-07')]
    public function testHostCanEditAndDeleteOnlyOwnProperty(): void
    {
        $owner = $this->user(Role::IlanSahibi);
        $intruder = $this->user(Role::IlanSahibi);
        $properties = $this->c->propertyService();
        $property = $properties->create((int) $owner->id, $this->input('Sahibin İlanı'));

        try {
            $properties->update((int) $intruder->id, (int) $property->id, $this->input('Ele Geçirildi'));
            self::fail('Başkasının ilanı düzenlenememeli');
        } catch (ForbiddenException) {
        }
        self::assertSame('Sahibin İlanı', $this->c->properties()->findById((int) $property->id)?->name);

        self::assertSame('Yeni Ad', $properties->update((int) $owner->id, (int) $property->id, $this->input('Yeni Ad'))->name);
        $this->expectException(ForbiddenException::class);
        $properties->delete((int) $intruder->id, (int) $property->id);
    }

    #[Group('FR-07')]
    #[Group('FR-08')]
    #[Group('AC-29')]
    public function testSearchFiltersByTypeRoomAmenityAndPriceAndSorts(): void
    {
        $host = $this->user(Role::IlanSahibi);
        $pool = $this->c->amenities()->save(new Amenity(null, 'Havuz'));
        $cheap = $this->property((int) $host->id, city: 'Ordu');
        $this->unit((int) $cheap->id, 800, 2, '1', RoomType::TekKisilik);
        $pricey = $this->property((int) $host->id, city: 'Ordu');
        $this->unit((int) $pricey->id, 2500, 4, '1', RoomType::Suit);
        $this->c->properties()->setAmenities((int) $pricey->id, [(int) $pool->id]);
        $house = $this->property((int) $host->id, type: PropertyType::KiralikEv, city: 'Ordu');
        $this->unit((int) $house->id, 1500, 6, 'Ev', RoomType::MustakilEv);

        $search = fn (array $args): array => $this->c->propertyService()->search(new SearchCriteria(...['city' => 'Ordu'] + $args))->items->items;

        self::assertSame([$cheap->name, $house->name, $pricey->name], $this->names($search([])), 'Varsayılan: fiyata göre artan');
        self::assertSame([$pricey->name, $house->name, $cheap->name], $this->names($search(['sort' => 'fiyat_azalan'])));
        self::assertSame([$house->name], $this->names($search(['type' => PropertyType::KiralikEv])));
        self::assertSame([$pricey->name], $this->names($search(['roomTypes' => [RoomType::Suit]])));
        self::assertSame([$pricey->name], $this->names($search(['amenityIds' => [(int) $pool->id]])));
        self::assertSame([$house->name], $this->names($search(['minPrice' => 100000, 'maxPrice' => 200000])));
        self::assertSame([$house->name], $this->names($search(['guests' => 5])), 'Kapasite filtresi');
        $invalid = $this->c->propertyService()->search(new SearchCriteria(city: 'Ordu', minPrice: 300000, maxPrice: 100000));
        self::assertArrayHasKey('fiyat', $invalid->errors);
    }

    #[Group('FR-19')]
    public function testFavoritesCanBeAddedAndRemoved(): void
    {
        $guest = $this->user();
        $property = $this->property((int) $this->user(Role::IlanSahibi)->id);
        $favorites = $this->c->favoriteService();

        self::assertTrue($favorites->toggle((int) $guest->id, (int) $property->id));
        self::assertSame([(int) $property->id], $favorites->propertyIds((int) $guest->id));
        self::assertFalse($favorites->toggle((int) $guest->id, (int) $property->id));
        self::assertSame([], $favorites->propertyIds((int) $guest->id));
    }

    #[Group('FR-25')]
    #[Group('FR-41')]
    #[Group('BR-10')]
    public function testAdminModerationHidesReviewAndRecordsTheReason(): void
    {
        $admin = $this->user(Role::Yonetici);
        $guest = $this->user();
        $property = $this->property((int) $this->user(Role::IlanSahibi)->id);
        $stay = $this->bookAndPay((int) $guest->id, $this->unit((int) $property->id), '2026-11-02', '2026-11-04');
        $this->clock->set('2026-11-05 09:00:00');
        $this->c->reservationService()->completeStays();
        self::assertSame(ReservationStatus::Tamamlandi, $this->c->reservations()->findById((int) $stay->id)?->status);
        $review = $this->c->reviewService()->create((int) $guest->id, $stay->number, 2, 'Oda temiz değildi, personel ilgisizdi.');
        self::assertCount(1, $this->c->reviews()->findForProperty((int) $property->id));

        $this->c->reviewService()->moderate((int) $admin->id, (int) $review->id, ModerationStatus::Gizlendi, 'Kişisel bilgi içeriyor');

        self::assertSame([], $this->c->reviews()->findForProperty((int) $property->id), 'Gizlenen yorum ilanda görünmez');
        $logs = array_values(array_filter($this->c->auditLogs()->all(), fn (AuditLog $l): bool => $l->entityType === 'review'));
        self::assertNotEmpty($logs);
        self::assertSame((int) $admin->id, $logs[0]->actorId);
        self::assertSame('Kişisel bilgi içeriyor', $logs[0]->reason, 'BR-10: işlemin nedeni kaydedilir');
    }

    #[Group('FR-27')]
    #[Group('BR-10')]
    public function testComplaintIsCreatedAndResolvedByAdmin(): void
    {
        $admin = $this->user(Role::Yonetici);
        $guest = $this->user();
        $property = $this->property((int) $this->user(Role::IlanSahibi)->id);

        try {
            $this->c->complaintService()->create((int) $guest->id, (int) $property->id, '', 'kısa');
            self::fail('Çok kısa şikâyet reddedilmeli');
        } catch (ValidationException) {
        }
        $complaint = $this->c->complaintService()->create((int) $guest->id, (int) $property->id, '', 'İlandaki fotoğraflar gerçeği yansıtmıyor.');
        self::assertSame(ComplaintStatus::Yeni, $complaint->status);

        $resolved = $this->c->complaintService()->update((int) $admin->id, (int) $complaint->id, ComplaintStatus::Cozuldu, 'İlan sahibiyle görüşüldü.');
        self::assertSame(ComplaintStatus::Cozuldu, $resolved->status);
        self::assertSame('İlan sahibiyle görüşüldü.', $this->c->complaints()->findById((int) $complaint->id)?->adminNote);
    }

    #[Group('FR-26')]
    public function testImportantEventsCreateNotificationsForBothSides(): void
    {
        $host = $this->user(Role::IlanSahibi);
        $guest = $this->user();
        $unit = $this->unit((int) $this->property((int) $host->id, BookingMode::Onayli)->id);
        $notifications = $this->c->notificationService();

        $request = $this->book((int) $guest->id, $unit, '2026-11-10', '2026-11-12');
        self::assertSame(1, $notifications->unreadCount((int) $guest->id), 'Müşteriye: rezervasyon oluşturuldu');
        self::assertSame(1, $notifications->unreadCount((int) $host->id), 'İlan sahibine: yeni talep');

        $this->c->reservationService()->reject($request->number, (int) $host->id, 'Tadilat var');
        self::assertSame(2, $notifications->unreadCount((int) $guest->id), 'Müşteriye: talep reddedildi');

        $notifications->markAllRead((int) $guest->id);
        self::assertSame(0, $notifications->unreadCount((int) $guest->id));
    }

    #[Group('FR-29')]
    #[Group('BR-10')]
    public function testAdminRestrictsAccountOnlyWithAReason(): void
    {
        $admin = $this->user(Role::Yonetici);
        $user = $this->user();
        $users = $this->c->userService();

        try {
            $users->setStatus((int) $admin->id, (int) $user->id, UserStatus::Kisitli, 'yok');
            self::fail('Nedensiz kısıtlama reddedilmeli');
        } catch (ValidationException) {
        }
        self::assertSame(UserStatus::Kisitli, $users->setStatus((int) $admin->id, (int) $user->id, UserStatus::Kisitli, 'Sahte rezervasyon denemeleri')->status);
        $log = array_values(array_filter($this->c->auditLogs()->all(), fn (AuditLog $l): bool => $l->entityType === 'user'))[0] ?? null;
        self::assertSame('Sahte rezervasyon denemeleri', $log?->reason);
    }

    #[Group('FR-31')]
    public function testUserUpdatesOwnProfile(): void
    {
        $user = $this->user();
        $updated = $this->c->userService()->updateProfile((int) $user->id, 'Zeynep', 'Demir');

        self::assertSame(['Zeynep', 'Demir'], [$updated->firstName, $updated->lastName]);
        $this->expectException(ValidationException::class);
        $this->c->userService()->updateProfile((int) $user->id, '', 'Demir');
    }
}
