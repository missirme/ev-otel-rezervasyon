<?php

declare(strict_types=1);

namespace App\Repository\Json;

use App\Model\PasswordResetToken;
use App\Repository\PasswordResetTokenRepositoryInterface;
use DateTimeImmutable;

final class JsonPasswordResetTokenRepository implements PasswordResetTokenRepositoryInterface
{
    private const TABLE = 'password_reset_tokens';

    public function __construct(private readonly JsonDatabase $db)
    {
    }

    public function save(PasswordResetToken $token): PasswordResetToken
    {
        return PasswordResetToken::fromArray($this->db->insert(self::TABLE, $token->toArray()));
    }

    public function findByHash(string $tokenHash): ?PasswordResetToken
    {
        $row = $this->db->first(self::TABLE, fn (array $r): bool => hash_equals((string) $r['token_hash'], $tokenHash));

        return $row === null ? null : PasswordResetToken::fromArray($row);
    }

    public function markUsedIfUnused(int $id, DateTimeImmutable $usedAt): bool
    {
        return $this->db->update(
            self::TABLE,
            fn (array $r): bool => $r['id'] === $id && $r['used_at'] === null,
            ['used_at' => $usedAt->format('Y-m-d H:i:s')]
        ) === 1;
    }

    public function invalidateForUser(int $userId, DateTimeImmutable $at): void
    {
        $this->db->update(
            self::TABLE,
            fn (array $r): bool => $r['user_id'] === $userId && $r['used_at'] === null,
            ['used_at' => $at->format('Y-m-d H:i:s')]
        );
    }
}
