<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\LoginAttempt;

interface LoginAttemptRepositoryInterface
{
    public function find(string $email): ?LoginAttempt;

    public function save(LoginAttempt $attempt): void;

    public function delete(string $email): void;
}
