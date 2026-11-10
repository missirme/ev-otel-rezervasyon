<?php

declare(strict_types=1);

namespace App\Repository\Json;

use App\Model\LoginAttempt;
use App\Repository\LoginAttemptRepositoryInterface;

final class JsonLoginAttemptRepository implements LoginAttemptRepositoryInterface
{
    private const TABLE = 'login_attempts';

    public function __construct(private readonly JsonDatabase $db)
    {
    }

    public function find(string $email): ?LoginAttempt
    {
        $row = $this->db->first(self::TABLE, fn (array $r): bool => $r['email'] === $email);

        return $row === null ? null : LoginAttempt::fromArray($row);
    }

    public function save(LoginAttempt $attempt): void
    {
        $this->db->transaction(function () use ($attempt): void {
            $this->delete($attempt->email);
            $this->db->insert(self::TABLE, $attempt->toArray());
        });
    }

    public function delete(string $email): void
    {
        $this->db->delete(self::TABLE, fn (array $r): bool => $r['email'] === $email);
    }
}
