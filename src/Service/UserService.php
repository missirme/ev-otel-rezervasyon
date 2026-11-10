<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Model\Role;
use App\Model\User;
use App\Model\UserStatus;
use App\Repository\TransactionManagerInterface;
use App\Repository\UserRepositoryInterface;
use App\Support\Paginator;
use App\Support\Text;

/** Profil (FR-31) ve yöneticinin kullanıcı yönetimi (FR-29). */
final class UserService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly AuditService $audit,
        private readonly TransactionManagerInterface $tx,
    ) {
    }

    public function find(int $userId): ?User
    {
        return $this->users->findById($userId);
    }

    /** Yalnızca ad ve soyad değiştirilebilir; rol ve e-posta değiştirilemez (SRS 11.5 açık karar önerisi). */
    public function updateProfile(int $userId, string $firstName, string $lastName): User
    {
        $errors = [];
        if (trim($firstName) === '' || Text::length($firstName) > 50) {
            $errors['ad'] = 'Ad zorunludur (en çok 50 karakter).';
        }
        if (trim($lastName) === '' || Text::length($lastName) > 50) {
            $errors['soyad'] = 'Soyad zorunludur (en çok 50 karakter).';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->tx->transactional(function () use ($userId, $firstName, $lastName): User {
            $user = $this->users->findById($userId) ?? throw new NotFoundException('Kullanıcı bulunamadı.');
            $user->firstName = trim($firstName);
            $user->lastName = trim($lastName);

            return $this->users->save($user);
        });
    }

    /** @return Paginator<User> */
    public function search(string $query, ?Role $role, ?UserStatus $status, int $page, int $perPage): Paginator
    {
        $list = array_values(array_filter(
            $this->users->all(),
            fn (User $u): bool => ($role === null || $u->role === $role)
                && ($status === null || $u->status === $status)
                && Text::contains($u->fullName() . ' ' . $u->email, $query)
        ));
        usort($list, fn (User $a, User $b): int => (int) $a->id <=> (int) $b->id);

        return new Paginator($list, $page, $perPage);
    }

    /** Hesap durumu değişikliği nedeniyle birlikte denetim kaydına yazılır (BR-10, FR-41). */
    public function setStatus(int $adminId, int $userId, UserStatus $status, string $reason): User
    {
        $reason = trim($reason);
        if (Text::length($reason) < 5 || Text::length($reason) > 500) {
            throw ValidationException::single('neden', 'Neden 5-500 karakter olmalıdır.');
        }

        return $this->tx->transactional(function () use ($adminId, $userId, $status, $reason): User {
            $user = $this->users->findById($userId) ?? throw new NotFoundException('Kullanıcı bulunamadı.');
            if ($user->role === Role::Yonetici) {
                throw ValidationException::single('neden', 'Yönetici hesaplarının durumu buradan değiştirilemez.');
            }
            $old = $user->status->value;
            $user->status = $status;
            $this->users->save($user);
            $this->audit->log($adminId, 'user', $userId, 'hesap_durumu', $old, $status->value, $reason);

            return $user;
        });
    }
}
