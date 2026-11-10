<?php

declare(strict_types=1);

namespace App\Model;

use App\Support\Row;
use DateTimeImmutable;

/** Kullanıcı (SRS 7). Parola yalnızca password_hash çıktısı olarak tutulur (NFR-02). */
final class User
{
    public function __construct(
        public ?int $id,
        public string $firstName,
        public string $lastName,
        public string $email,
        public string $passwordHash,
        public Role $role,
        public UserStatus $status,
        public DateTimeImmutable $createdAt,
    ) {
    }

    public function fullName(): string
    {
        return trim($this->firstName . ' ' . $this->lastName);
    }

    public function is(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /** @param array<string, mixed> $r */
    public static function fromArray(array $r): self
    {
        return new self(
            Row::int($r, 'id'),
            Row::string($r, 'first_name'),
            Row::string($r, 'last_name'),
            Row::string($r, 'email'),
            Row::string($r, 'password_hash'),
            Role::from(Row::string($r, 'role')),
            UserStatus::from(Row::string($r, 'status')),
            Row::dateTime($r, 'created_at'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'email' => $this->email,
            'password_hash' => $this->passwordHash,
            'role' => $this->role->value,
            'status' => $this->status->value,
            'created_at' => Row::fmtDateTime($this->createdAt),
        ];
    }
}
