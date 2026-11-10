<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exception\ValidationException;
use App\Model\Role;
use App\Model\UserStatus;
use Tests\Support\AppTestCase;
use PHPUnit\Framework\Attributes\Group;

/** FR-01 - FR-04, NFR-02, NFR-15: kayıt, parola özeti, giriş kısıtı, sıfırlama jetonu, şifre değiştirme. */
final class AuthServiceTest extends AppTestCase
{
    #[Group('FR-01')]
    #[Group('AC-01')]
    #[Group('NFR-02')]
    public function testRegisterStoresPasswordHashNotPlainText(): void
    {
        $user = $this->c->authService()->register('Ayşe', 'Kaya', 'AYSE@Example.com', 'GizliParola1', Role::Musteri);

        self::assertSame('ayse@example.com', $user->email);
        self::assertNotSame('GizliParola1', $user->passwordHash);
        self::assertTrue(password_verify('GizliParola1', $user->passwordHash));
        self::assertStringNotContainsString('GizliParola1', $this->rawTable('users'), 'Depoda düz metin parola yok');
    }

    #[Group('FR-01')]
    #[Group('NFR-06')]
    public function testRegisterValidationAndUniqueEmail(): void
    {
        $auth = $this->c->authService();
        try {
            $auth->register('', 'Kaya', 'gecersiz', 'kisa', Role::Musteri);
            self::fail('Geçersiz kayıt reddedilmeli');
        } catch (ValidationException $e) {
            self::assertEqualsCanonicalizing(['ad', 'eposta', 'parola'], array_keys($e->errors()));
        }
        $auth->register('Ali', 'Veli', 'ali@example.com', 'Parola1234', Role::Musteri);

        $this->expectException(ValidationException::class);
        $auth->register('Ali', 'Başka', 'ALI@example.com', 'Parola1234', Role::IlanSahibi);
    }

    #[Group('FR-01')]
    #[Group('FR-05')]
    public function testAdminAccountCannotBeSelfRegistered(): void
    {
        $this->expectException(ValidationException::class);
        $this->c->authService()->register('Kötü', 'Niyet', 'admin@example.com', 'Parola1234', Role::Yonetici);
    }

    #[Group('FR-02')]
    #[Group('NFR-15')]
    public function testFiveFailedLoginsLockForFifteenMinutes(): void
    {
        $auth = $this->c->authService();
        $auth->register('Can', 'Öz', 'can@example.com', 'DogruParola1', Role::Musteri);

        for ($i = 1; $i <= 5; $i++) {
            try {
                $auth->login('can@example.com', 'yanlis');
            } catch (ValidationException) {
            }
        }
        try {
            $auth->login('can@example.com', 'DogruParola1');
            self::fail('Kilit süresince doğru parola da reddedilmeli');
        } catch (ValidationException $e) {
            self::assertStringContainsString('dakika', $e->getMessage());
        }

        $this->clock->advance('+16 minutes');
        self::assertSame('can@example.com', $auth->login('can@example.com', 'DogruParola1')->email);
    }

    #[Group('FR-02')]
    #[Group('FR-29')]
    public function testRestrictedAccountCannotLogIn(): void
    {
        $auth = $this->c->authService();
        $user = $auth->register('Deniz', 'Ay', 'deniz@example.com', 'Parola1234', Role::Musteri);
        $user->status = UserStatus::Kisitli;
        $this->c->users()->save($user);

        $this->expectException(ValidationException::class);
        $auth->login('deniz@example.com', 'Parola1234');
    }

    #[Group('FR-04')]
    #[Group('NFR-02')]
    public function testResetTokenIsSingleUseAndExpiresAfter60Minutes(): void
    {
        $auth = $this->c->authService();
        $auth->register('Selin', 'Ar', 'selin@example.com', 'EskiParola1', Role::Musteri);
        $token = '';
        $auth->requestPasswordReset('selin@example.com', function (string $t) use (&$token): string {
            $token = $t;

            return 'http://test/parola-sifirla/' . $t;
        });
        self::assertSame(64, strlen($token));
        self::assertStringNotContainsString($token, $this->rawTable('password_reset_tokens'), 'Jetonun kendisi değil özeti saklanır');

        $auth->resetPassword($token, 'YeniParola1');
        self::assertSame('selin@example.com', $auth->login('selin@example.com', 'YeniParola1')->email);
        try {
            $auth->resetPassword($token, 'BaskaParola1');
            self::fail('Jeton ikinci kez kullanılamaz');
        } catch (ValidationException) {
        }

        $second = '';
        $auth->requestPasswordReset('selin@example.com', function (string $t) use (&$second): string {
            $second = $t;

            return $t;
        });
        $this->clock->advance('+61 minutes');
        $this->expectException(ValidationException::class);
        $auth->resetPassword($second, 'GecikmisParola1');
    }

    #[Group('FR-31')]
    public function testChangePasswordRequiresCurrentPassword(): void
    {
        $auth = $this->c->authService();
        $user = $auth->register('Burak', 'Koç', 'burak@example.com', 'Parola1234', Role::Musteri);

        try {
            $auth->changePassword((int) $user->id, 'yanlis', 'YeniParola99');
            self::fail('Mevcut parola doğrulanmalı');
        } catch (ValidationException) {
        }
        $auth->changePassword((int) $user->id, 'Parola1234', 'YeniParola99');
        self::assertSame((int) $user->id, $auth->login('burak@example.com', 'YeniParola99')->id);
    }
}
