<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Kernel;
use App\Core\Request;
use App\Exception\ConflictException;
use App\Exception\ValidationException;
use App\Model\CancellationPolicy;
use App\Model\Reservation;
use App\Model\ReservationStatus;
use App\Model\Role;
use App\Support\Settings;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AppTestCase;
use Tests\Support\TestSettings;

/**
 * NFR-10, NFR-17, NFR-18: günlük kayıtları hassas veri içermez; beklenmeyen hata türüyle kaydedilir ve
 * kullanıcıya genel mesaj gösterilir; tutarlılık ihlali denetim sorgusuyla bulunur; zaman kararları sunucu saatiyle verilir.
 */
final class LoggingAndAuditTest extends AppTestCase
{
    protected function settings(): Settings
    {
        return TestSettings::create($this->dir, $this->dir . '/logs/app.log');
    }

    private function log(): string
    {
        return is_file($this->dir . '/logs/app.log') ? (string) file_get_contents($this->dir . '/logs/app.log') : '';
    }

    #[Group('NFR-18')]
    #[Group('NFR-04')]
    public function testConflictsExpiriesAndTransitionsAreLoggedWithoutPersonalData(): void
    {
        $host = $this->user(Role::IlanSahibi);
        $guest = $this->user(Role::Musteri, 'gizli.musteri@test.local');
        $unit = $this->unit((int) $this->property((int) $host->id)->id);

        $hold = $this->book((int) $guest->id, $unit, '2026-11-10', '2026-11-12');
        try {
            $this->book((int) $this->user()->id, $unit, '2026-11-11', '2026-11-13');
            self::fail('Çakışma beklenirdi');
        } catch (ConflictException) {
        }
        $this->clock->advance('+11 minutes');
        $this->c->reservationService()->expireHolds();

        $log = $this->log();
        self::assertStringContainsString('rezervasyon_olusturuldu', $log);
        self::assertStringContainsString('cakisma_reddi', $log);
        self::assertMatchesRegularExpression('/durum_gecisi .*"eski":"beklemede","yeni":"suresi_doldu"/', $log, 'Süre dolumu durum geçişi olarak kaydedilir');
        foreach (['gizli.musteri@test.local', 'Test Müşteri', '0532 111 22 33', $hold->number] as $personal) {
            self::assertStringNotContainsString($personal, $log, 'Günlükte kişisel veri yok: ' . $personal);
        }
    }

    #[Group('NFR-10')]
    #[Group('NFR-02')]
    #[Group('NFR-15')]
    public function testPasswordsAndResetTokensNeverReachTheLog(): void
    {
        $auth = $this->c->authService();
        $auth->register('Deniz', 'Ak', 'deniz.ak@test.local', 'GizliParola1', Role::Musteri);
        try {
            $auth->login('deniz.ak@test.local', 'YanlisParola9');
            self::fail('Hatalı parola reddedilmeli');
        } catch (ValidationException) {
        }
        $token = '';
        $auth->requestPasswordReset('deniz.ak@test.local', function (string $t) use (&$token): string {
            $token = $t;

            return 'http://test/parola-sifirla/' . $t;
        });

        $log = $this->log();
        self::assertStringContainsString('basarisiz_giris', $log, 'Başarısız giriş izlenebilir');
        self::assertNotSame('', $token);
        foreach (['GizliParola1', 'YanlisParola9', $token, 'deniz.ak@test.local'] as $secret) {
            self::assertStringNotContainsString($secret, $log);
        }
    }

    #[Group('NFR-10')]
    #[Group('NFR-06')]
    public function testInjectedServerErrorIsLoggedWithTypeAndShownGenerically(): void
    {
        $_SESSION = [];
        $kernel = new Kernel($this->c);
        $this->user(); // depo dolu: ilk istekte örnek veri yüklenmez
        // Hata enjeksiyonu: ilan tablosu okunamaz hâle getirilir
        file_put_contents($this->dir . '/data/properties.json', '{bozuk');
        try {
            $response = $kernel->handle(new Request('GET', '/'));
        } finally {
            $_SESSION = [];
        }

        self::assertSame(500, $response->status);
        self::assertStringContainsString('Bir sorun oluştu', $response->body, 'Kullanıcıya genel ve anlaşılır mesaj');
        self::assertStringNotContainsString('Exception', $response->body, 'Ayrıntı (yığın izi, sınıf adı) kullanıcıya gösterilmez');
        self::assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] HATA beklenmeyen_hata .*"tur":/m', $this->log(), 'Zaman damgası ve hata türüyle kayıt');
    }

    /** T-11 / AC-22: bilinçli oluşturulan çift engelleyen kayıt denetim sorgusuyla bulunur. */
    #[Group('NFR-18')]
    #[Group('T-11')]
    #[Group('AC-22')]
    public function testConsistencyAuditFindsADeliberateDoubleBooking(): void
    {
        $unit = $this->unit((int) $this->property((int) $this->user(Role::IlanSahibi)->id)->id);
        $customer = (int) $this->user()->id;
        self::assertSame([], $this->c->consistencyAuditService()->violations(), 'Sağlıklı veride boş küme');

        $make = fn (string $number, array $nights): Reservation => $this->c->tx()->transactional(fn () => $this->c->reservations()->createWithNights(new Reservation(
            null,
            $number,
            $customer,
            (int) $unit->id,
            $this->d('2026-11-10'),
            $this->d('2026-11-12'),
            1,
            ReservationStatus::Onaylandi,
            null,
            200000,
            CancellationPolicy::Orta,
            'anahtar-' . $number,
            'Test',
            '0532 000 00 00',
            null,
            null,
            null,
            0,
            $this->clock->now(),
            $this->clock->now(),
        ), [], $nights));
        $make('RZ-20261101-CIFT01', [$this->d('2026-11-10'), $this->d('2026-11-11')]);
        $make('RZ-20261101-CIFT02', []); // gece kaydı olmadan aynı aralıkta ikinci onaylı kayıt (hatalı kod taklidi)

        $violations = $this->c->consistencyAuditService()->violations();
        self::assertNotEmpty(array_filter($violations, fn (string $v): bool => str_contains($v, 'aynı geceleri tutuyor')), implode("\n", $violations));
    }

    /** NFR-17: bekletme süresi sunucu saatiyle saniyesinde biter. */
    #[Group('NFR-17')]
    #[Group('BR-15')]
    public function testHoldExpiresExactlyAtServerTime(): void
    {
        $unit = $this->unit((int) $this->property((int) $this->user(Role::IlanSahibi)->id)->id);
        $hold = $this->book((int) $this->user()->id, $unit, '2026-11-10', '2026-11-12'); // 10:00:00, anında mod: 10 dakika
        $conflicts = $this->c->bookingConflictService();

        self::assertSame('2026-11-01 10:10:00', $hold->expiresAt?->format('Y-m-d H:i:s'));
        self::assertTrue($conflicts->isBlocking($hold, $this->clock->now()->modify('+9 minutes 59 seconds')), 'Bir saniye önce geçerli');
        self::assertFalse($conflicts->isBlocking($hold, $this->clock->now()->modify('+10 minutes 1 second')), 'Bir saniye sonra süresi dolmuş');
    }
}
