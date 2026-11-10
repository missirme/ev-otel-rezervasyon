<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Notification;
use App\Repository\NotificationRepositoryInterface;
use App\Support\ClockInterface;
use App\Support\Paginator;

/** Uygulama içi bildirimler (FR-26). */
final class NotificationService
{
    public function __construct(
        private readonly NotificationRepositoryInterface $notifications,
        private readonly ClockInterface $clock,
    ) {
    }

    public function notify(int $userId, string $type, string $message): void
    {
        $this->notifications->save(new Notification(null, $userId, $type, $message, false, $this->clock->now()));
    }

    /** @return Paginator<Notification> */
    public function listForUser(int $userId, int $page, int $perPage): Paginator
    {
        return new Paginator($this->notifications->findByUser($userId), $page, $perPage);
    }

    public function unreadCount(int $userId): int
    {
        return $this->notifications->unreadCount($userId);
    }

    public function markAllRead(int $userId): void
    {
        $this->notifications->markAllRead($userId);
    }
}
