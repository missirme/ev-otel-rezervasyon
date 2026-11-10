<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\User;

interface UserRepositoryInterface
{
    public function findById(int $id): ?User;

    public function findByEmail(string $email): ?User;

    /** id boşsa ekler, doluysa günceller. */
    public function save(User $user): User;

    /** @return list<User> */
    public function all(): array;
}
