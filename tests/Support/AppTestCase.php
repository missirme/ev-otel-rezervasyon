<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Container;
use App\Model\BookableUnit;
use App\Model\BookingMode;
use App\Model\CancellationPolicy;
use App\Model\PriceRule;
use App\Model\PriceRuleType;
use App\Model\PricingRule;
use App\Model\Property;
use App\Model\PropertyStatus;
use App\Model\PropertyType;
use App\Model\Reservation;
use App\Model\Role;
use App\Model\RoomType;
use App\Model\UnitStatus;
use App\Model\User;
use App\Model\UserStatus;
use App\Model\ValueType;
use App\Model\CalcMethod;
use App\Model\PricingRuleType;
use App\Service\Dto\CreateReservationRequest;
use App\Support\DateRange;
use App\Support\FrozenClock;
use App\Support\Settings;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Her test geçici bir veri klasörü ve sabit saatle (FrozenClock) çalışır;
 * gerçek veritabanı veya storage/data kullanılmaz (NFR-16).
 * "Bugün" 1 Kasım 2026 Pazar, saat 10:00 (Europe/Istanbul).
 */
abstract class AppTestCase extends TestCase
{
    protected Container $c;
    protected FrozenClock $clock;
    protected string $dir;


    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'evotel-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/data', 0777, true);
        $this->clock = new FrozenClock('2026-11-01 10:00:00');
        $this->c = new Container($this->settings(), $this->clock);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->dir);
    }

    protected function settings(): Settings
    {
        return TestSettings::create($this->dir);
    }

    /** Tablonun depodaki ham içeriği (JSON dosyası); "düz metin saklanmıyor" kontrolleri için. */
    protected function rawTable(string $table): string
    {
        return (string) file_get_contents($this->dir . '/data/' . $table . '.json');
    }

    public static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    protected function d(string $ymd): DateTimeImmutable
    {
        return DateRange::parseDate($ymd);
    }

    protected function range(string $in, string $out): DateRange
    {
        return DateRange::fromStrings($in, $out);
    }

    protected function user(Role $role = Role::Musteri, ?string $email = null): User
    {
        $email ??= $role->value . '-' . bin2hex(random_bytes(3)) . '@test.local';

        $hash = password_hash('Parola123', PASSWORD_DEFAULT, ['cost' => 4]);

        return $this->c->users()->save(new User(null, 'Test', ucfirst($role->value), $email, $hash, $role, UserStatus::Aktif, $this->clock->now()));
    }

    protected function property(
        int $ownerId,
        BookingMode $mode = BookingMode::Aninda,
        CancellationPolicy $policy = CancellationPolicy::Orta,
        PropertyType $type = PropertyType::Otel,
        string $city = 'Trabzon',
    ): Property {
        $name = 'Test ' . $city . ' ' . bin2hex(random_bytes(2));

        return $this->c->properties()->save(
            new Property(null, $ownerId, $name, $type, 'Test ilanı açıklaması yeterince uzun.', 'Merkez', $city, $mode, $policy, PropertyStatus::Aktif)
        );
    }

    protected function unit(int $propertyId, int $basePriceTl = 1000, int $capacity = 2, string $name = '101', RoomType $type = RoomType::CiftKisilik): BookableUnit
    {
        return $this->c->units()->save(new BookableUnit(null, $propertyId, $name, $type, $capacity, $basePriceTl * 100, UnitStatus::Aktif));
    }

    protected function seasonRule(int $propertyId, string $start, string $end, int $percent, int $priority = 1, string $name = 'Sezon'): PriceRule
    {
        return $this->c->priceRules()->save(
            new PriceRule(null, $propertyId, null, PriceRuleType::Sezon, $name, $this->d($start), $this->d($end), null, $percent * 100, ValueType::Yuzde, $priority)
        );
    }

    protected function weekendRule(int $propertyId, int $percent): PriceRule
    {
        return $this->c->priceRules()->save(new PriceRule(null, $propertyId, null, PriceRuleType::HaftaSonu, 'Hafta sonu', null, null, null, $percent * 100, ValueType::Yuzde, 1));
    }

    protected function longStayRule(int $propertyId, int $minNights, int $percent): PriceRule
    {
        return $this->c->priceRules()->save(
            new PriceRule(null, $propertyId, null, PriceRuleType::UzunKonaklama, 'Uzun', null, null, $minNights, $percent * 100, ValueType::Yuzde, 1)
        );
    }

    protected function taxRule(int $propertyId, int $percent): PricingRule
    {
        return $this->c->pricingRules()->save(new PricingRule(null, $propertyId, 'KDV', PricingRuleType::Vergi, CalcMethod::Yuzde, $percent * 100));
    }

    /** Servis üzerinden (tüm kurallarla) rezervasyon oluşturur. */
    protected function book(int $customerId, BookableUnit $unit, string $in, string $out, int $guests = 1, ?string $key = null): Reservation
    {
        $range = $this->range($in, $out);
        $quote = $this->c->pricingService()->quote($unit, $range);

        return $this->c->reservationService()->create(new CreateReservationRequest(
            $customerId,
            (int) $unit->id,
            $range,
            $guests,
            $key ?? bin2hex(random_bytes(16)),
            $quote->total,
            'Test Müşteri',
            '0532 111 22 33',
            null,
        ))->reservation;
    }

    /** Ödemesi alınmış ve (anında modda) onaylanmış rezervasyon. */
    protected function bookAndPay(int $customerId, BookableUnit $unit, string $in, string $out, int $guests = 1): Reservation
    {
        $reservation = $this->book($customerId, $unit, $in, $out, $guests);
        $this->c->reservationService()->pay($reservation->number, $customerId, true);

        return $this->c->reservations()->findById((int) $reservation->id) ?? $reservation;
    }
}
