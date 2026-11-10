<?php

declare(strict_types=1);

namespace App\Core;

use App\Model\Role;
use App\Model\User;
use App\Model\UserStatus;
use App\Repository\UserRepositoryInterface;

/** Oturumdaki kullanıcı. Her istekte kullanıcı yeniden okunur; kısıtlanan hesap anında çıkış yapar. */
final class Auth
{
    private const KEY = 'user_id';
    private ?User $user = null;
    private bool $loaded = false;

    public function __construct(private readonly Session $session, private readonly UserRepositoryInterface $users)
    {
    }

    public function user(): ?User
    {
        if (!$this->loaded) {
            $this->loaded = true;
            $id = $this->session->get(self::KEY);
            $user = is_int($id) ? $this->users->findById($id) : null;
            if ($user !== null && $user->status === UserStatus::Kisitli) {
                $this->session->remove(self::KEY);
                $user = null;
            }
            $this->user = $user;
        }

        return $this->user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function hasRole(Role ...$roles): bool
    {
        $user = $this->user();

        return $user !== null && in_array($user->role, $roles, true);
    }

    public function login(User $user): void
    {
        $this->session->regenerate();
        $this->session->set(self::KEY, $user->id);
        $this->user = $user;
        $this->loaded = true;
    }

    public function logout(): void
    {
        $this->session->destroy();
        $this->user = null;
        $this->loaded = true;
    }
}
