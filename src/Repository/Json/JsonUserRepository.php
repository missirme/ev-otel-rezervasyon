<?php

declare(strict_types=1);

namespace App\Repository\Json;

use App\Model\User;
use App\Repository\UserRepositoryInterface;

final class JsonUserRepository implements UserRepositoryInterface
{
    private const TABLE = 'users';

    public function __construct(private readonly JsonDatabase $db)
    {
    }

    public function findById(int $id): ?User
    {
        $row = $this->db->first(self::TABLE, fn (array $r): bool => $r['id'] === $id);

        return $row === null ? null : User::fromArray($row);
    }

    public function findByEmail(string $email): ?User
    {
        $email = mb_strtolower(trim($email));
        $row = $this->db->first(self::TABLE, fn (array $r): bool => $r['email'] === $email);

        return $row === null ? null : User::fromArray($row);
    }

    public function save(User $user): User
    {
        if ($user->id === null) {
            return User::fromArray($this->db->insert(self::TABLE, $user->toArray()));
        }
        $id = $user->id;
        $this->db->update(self::TABLE, fn (array $r): bool => $r['id'] === $id, $user->toArray());

        return $user;
    }

    public function all(): array
    {
        return array_map(User::fromArray(...), $this->db->select(self::TABLE));
    }
}
