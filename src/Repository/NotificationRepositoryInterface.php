<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Notification;

interface NotificationRepositoryInterface
{
    public function save(Notification $notification): Notification;

    /**
     * En yeni önce.
     *
     * @return list<Notification>
     */
    public function findByUser(int $userId): array;

    public function unreadCount(int $userId): int;

    public function markAllRead(int $userId): int;
}
