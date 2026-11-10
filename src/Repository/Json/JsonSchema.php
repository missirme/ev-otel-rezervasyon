<?php

declare(strict_types=1);

namespace App\Repository\Json;

/**
 * Aşama 2 tablo yapısı. ER diyagramındaki (docs/tasarim.md bölüm 3) tabloları ve
 * anahtarları izler; Aşama 3'te aynı yapı SQL tablolarına dönüşür (SRS 2.3).
 *
 * pk     : birincil anahtar sütun(lar)ı
 * auto   : id otomatik artan mı
 * unique : benzersizlik kısıtları (UNIQUE)
 */
final class JsonSchema
{
    /** @return array<string, array{pk: list<string>, auto: bool, unique: list<list<string>>}> */
    public static function tables(): array
    {
        $id = static fn (array $unique = []): array => ['pk' => ['id'], 'auto' => true, 'unique' => $unique];

        return [
            'users' => $id([['email']]),
            'login_attempts' => ['pk' => ['email'], 'auto' => false, 'unique' => []],
            'password_reset_tokens' => $id([['token_hash']]),
            'properties' => $id(),
            'bookable_units' => $id([['property_id', 'name']]),
            'amenities' => $id([['name']]),
            'property_amenities' => ['pk' => ['property_id', 'amenity_id'], 'auto' => false, 'unique' => []],
            'photos' => $id([['file_name']]),
            'calendar_entries' => $id([['unit_id', 'date']]),
            'price_rules' => $id(),
            'pricing_rules' => $id(),
            'reservations' => $id([['reservation_no'], ['idempotency_key']]),
            // Çekirdek kısıt: aynı birimin aynı gecesi yalnızca bir kez tutulabilir (SRS 4.5, 7.1)
            'reservation_nights' => ['pk' => ['reservation_id', 'night_date'], 'auto' => false, 'unique' => [['unit_id', 'night_date']]],
            'reservation_charges' => $id(),
            'payments' => $id([['provider_ref']]),
            'reviews' => $id([['reservation_id']]),
            'favorites' => ['pk' => ['user_id', 'property_id'], 'auto' => false, 'unique' => []],
            'notifications' => $id(),
            'complaints' => $id(),
            'audit_logs' => $id(),
        ];
    }
}
