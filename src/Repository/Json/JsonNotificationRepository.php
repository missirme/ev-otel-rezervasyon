<?php

declare(strict_types=1);

namespace App\Repository\Json;

use App\Model\Notification;
use App\Repository\NotificationRepositoryInterface;

final class JsonNotificationRepository implements NotificationRepositoryInterface
{
    private const TABLE = 'notifications';

    public function __construct(private readonly JsonDatabase $db)
    {
    }

    public function save(Notification $notification): Notification
    {
        if ($notification->id === null) {
            return Notification::fromArray($this->db->insert(self::TABLE, $notification->toArray()));
        }
        $id = $notification->id;
        $this->db->update(self::TABLE, fn (array $r): bool => $r['id'] === $id, $notification->toArray());

        return $notification;
    }

    public function findByUser(int $userId): array
    {
        $list = array_map(
            Notification::fromArray(...),
            $this->db->select(self::TABLE, fn (array $r): bool => $r['user_id'] === $userId)
        );
        usort($list, fn (Notification $a, Notification $b): int => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);

        return $list;
    }

    public function unreadCount(int $userId): int
    {
        return count($this->db->select(self::TABLE, fn (array $r): bool => $r['user_id'] === $userId && $r['is_read'] === false));
    }

    public function markAllRead(int $userId): int
    {
        return $this->db->update(
            self::TABLE,
            fn (array $r): bool => $r['user_id'] === $userId && $r['is_read'] === false,
            ['is_read' => true]
        );
    }
}
