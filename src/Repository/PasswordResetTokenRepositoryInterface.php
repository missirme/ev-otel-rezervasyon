<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\PasswordResetToken;
use DateTimeImmutable;

interface PasswordResetTokenRepositoryInterface
{
    public function save(PasswordResetToken $token): PasswordResetToken;

    public function findByHash(string $tokenHash): ?PasswordResetToken;

    /** Koşullu güncelleme: jeton henüz kullanılmadıysa kullanıldı işaretler (tek kullanımlık). */
    public function markUsedIfUnused(int $id, DateTimeImmutable $usedAt): bool;

    /** Kullanıcının açık jetonlarını geçersiz kılar. */
    public function invalidateForUser(int $userId, DateTimeImmutable $at): void;
}
