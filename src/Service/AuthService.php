<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ValidationException;
use App\Model\LoginAttempt;
use App\Model\PasswordResetToken;
use App\Model\Role;
use App\Model\User;
use App\Model\UserStatus;
use App\Repository\LoginAttemptRepositoryInterface;
use App\Repository\PasswordResetTokenRepositoryInterface;
use App\Repository\TransactionManagerInterface;
use App\Repository\UserRepositoryInterface;
use App\Service\Mail\MailerInterface;
use App\Support\ClockInterface;
use App\Support\Logger;
use App\Support\Settings;
use App\Support\Text;

/**
 * Kayıt, giriş ve parola işlemleri (FR-01 - FR-04, NFR-02, NFR-15).
 * Parolalar password_hash ile saklanır, password_verify ile doğrulanır.
 */
final class AuthService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly LoginAttemptRepositoryInterface $attempts,
        private readonly PasswordResetTokenRepositoryInterface $tokens,
        private readonly MailerInterface $mailer,
        private readonly TransactionManagerInterface $tx,
        private readonly ClockInterface $clock,
        private readonly Settings $settings,
        private readonly Logger $logger,
    ) {
    }

    /** FR-01: ad, soyad, e-posta ve parolayla hesap. Yönetici hesabı kayıtla açılamaz. */
    public function register(string $firstName, string $lastName, string $email, string $password, Role $role): User
    {
        $email = Text::lower(trim($email));
        $errors = [];
        if (trim($firstName) === '' || Text::length($firstName) > 50) {
            $errors['ad'] = 'Ad zorunludur (en çok 50 karakter).';
        }
        if (trim($lastName) === '' || Text::length($lastName) > 50) {
            $errors['soyad'] = 'Soyad zorunludur (en çok 50 karakter).';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            $errors['eposta'] = 'Geçerli bir e-posta adresi girin (en çok 254 karakter).';
        }
        if (($passwordError = $this->passwordError($password)) !== null) {
            $errors['parola'] = $passwordError;
        }
        if ($role === Role::Yonetici) {
            $errors['rol'] = 'Geçersiz hesap türü.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->tx->transactional(function () use ($firstName, $lastName, $email, $password, $role): User {
            if ($this->users->findByEmail($email) !== null) {
                throw ValidationException::single('eposta', 'Bu e-posta zaten kayıtlı.');
            }

            return $this->users->save(new User(
                null,
                trim($firstName),
                trim($lastName),
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                $role,
                UserStatus::Aktif,
                $this->clock->now(),
            ));
        });
    }

    /** FR-02, NFR-15: art arda 5 başarısız denemeden sonra 15 dakika kısıt. */
    public function login(string $email, string $password): User
    {
        $email = Text::lower(trim($email));
        if ($email === '' || strlen($email) > 254) {
            throw ValidationException::single('giris', 'E-posta veya parola hatalı.');
        }
        $now = $this->clock->now();

        // Hata durumunda da sayaç yazılsın diye işlem hata atmaz; mesajı döndürür, hata işlem dışında atılır.
        $outcome = $this->tx->transactional(function () use ($email, $password, $now): User|string {
            $attempt = $this->attempts->find($email);
            if ($attempt?->lockedUntil !== null && $attempt->lockedUntil > $now) {
                $minutes = (int) ceil(($attempt->lockedUntil->getTimestamp() - $now->getTimestamp()) / 60);

                return sprintf('Çok fazla hatalı deneme yapıldı. Lütfen %d dakika sonra tekrar deneyin.', max(1, $minutes));
            }

            $user = $this->users->findByEmail($email);
            if ($user === null || !password_verify($password, $user->passwordHash)) {
                $previous = ($attempt !== null && $attempt->lockedUntil === null) ? $attempt->failedCount : 0;
                $count = $previous + 1;
                $locked = $count >= $this->settings->loginMaxAttempts
                    ? $now->modify('+' . $this->settings->loginLockMinutes . ' minutes')
                    : null;
                $this->attempts->save(new LoginAttempt($email, $locked !== null ? 0 : $count, $now, $locked));
                $this->logger->warning('basarisiz_giris', ['eposta_ozeti' => substr(hash('sha256', $email), 0, 12)]);

                return $locked !== null
                    ? sprintf('Art arda %d hatalı deneme yapıldı; giriş %d dakika kısıtlandı.', $this->settings->loginMaxAttempts, $this->settings->loginLockMinutes)
                    : 'E-posta veya parola hatalı.';
            }
            if ($user->status === UserStatus::Kisitli) {
                return 'Hesabınız yönetici tarafından kısıtlanmıştır.';
            }
            $this->attempts->delete($email);
            if (password_needs_rehash($user->passwordHash, PASSWORD_DEFAULT)) {
                $user->passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $this->users->save($user);
            }

            return $user;
        });
        if (is_string($outcome)) {
            throw ValidationException::single('giris', $outcome);
        }

        return $outcome;
    }

    /**
     * FR-04: tek kullanımlık, 60 dakika geçerli sıfırlama jetonu. Veritabanında yalnızca özeti saklanır.
     * Kullanıcı olsun olmasın aynı yanıt verilir (hesap varlığı sızdırılmaz).
     *
     * @param callable(string): string $linkFor jetondan bağlantı üretir
     */
    public function requestPasswordReset(string $email, callable $linkFor): void
    {
        $user = $this->users->findByEmail(Text::lower(trim($email)));
        if ($user === null || $user->status !== UserStatus::Aktif) {
            return;
        }
        $token = bin2hex(random_bytes(32));
        $now = $this->clock->now();
        $this->tx->transactional(function () use ($user, $token, $now): void {
            $this->tokens->invalidateForUser((int) $user->id, $now);
            $this->tokens->save(new PasswordResetToken(
                null,
                (int) $user->id,
                hash('sha256', $token),
                $now->modify('+' . $this->settings->passwordResetMinutes . ' minutes'),
                null,
            ));
        });
        $this->mailer->send(
            $user->email,
            'Parola sıfırlama',
            "Merhaba {$user->firstName},\n\nParolanızı sıfırlamak için bağlantı ("
                . $this->settings->passwordResetMinutes . " dakika geçerli, tek kullanımlık):\n"
                . $linkFor($token)
        );
    }

    public function resetPassword(string $token, string $newPassword): void
    {
        if (($error = $this->passwordError($newPassword)) !== null) {
            throw ValidationException::single('parola', $error);
        }
        $now = $this->clock->now();
        $this->tx->transactional(function () use ($token, $newPassword, $now): void {
            $record = $this->tokens->findByHash(hash('sha256', $token));
            if ($record === null || $record->usedAt !== null || $record->expiresAt <= $now) {
                throw ValidationException::single('jeton', 'Bağlantı geçersiz, kullanılmış veya süresi dolmuş. Lütfen yeniden talep edin.');
            }
            if (!$this->tokens->markUsedIfUnused((int) $record->id, $now)) {
                throw ValidationException::single('jeton', 'Bu bağlantı zaten kullanılmış.');
            }
            $user = $this->users->findById($record->userId) ?? throw ValidationException::single('jeton', 'Kullanıcı bulunamadı.');
            $user->passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $this->users->save($user);
        });
    }

    /** Bonus: şifre değiştirme. */
    public function changePassword(int $userId, string $current, string $new): void
    {
        $this->tx->transactional(function () use ($userId, $current, $new): void {
            $user = $this->users->findById($userId) ?? throw ValidationException::single('mevcut', 'Kullanıcı bulunamadı.');
            if (!password_verify($current, $user->passwordHash)) {
                throw ValidationException::single('mevcut', 'Mevcut parola hatalı.');
            }
            if (($error = $this->passwordError($new)) !== null) {
                throw ValidationException::single('yeni', $error);
            }
            if (password_verify($new, $user->passwordHash)) {
                throw ValidationException::single('yeni', 'Yeni parola mevcut paroladan farklı olmalıdır.');
            }
            $user->passwordHash = password_hash($new, PASSWORD_DEFAULT);
            $this->users->save($user);
        });
    }

    private function passwordError(string $password): ?string
    {
        if (strlen($password) < 8) {
            return 'Parola en az 8 karakter olmalıdır.';
        }
        if (strlen($password) > 72) {
            return 'Parola en çok 72 karakter olabilir.';
        }

        return null;
    }
}
