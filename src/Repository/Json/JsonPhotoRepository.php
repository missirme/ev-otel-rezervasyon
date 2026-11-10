<?php

declare(strict_types=1);

namespace App\Repository\Json;

use App\Model\Photo;
use App\Repository\PhotoRepositoryInterface;

final class JsonPhotoRepository implements PhotoRepositoryInterface
{
    private const TABLE = 'photos';

    public function __construct(private readonly JsonDatabase $db)
    {
    }

    public function findById(int $id): ?Photo
    {
        $row = $this->db->first(self::TABLE, fn (array $r): bool => $r['id'] === $id);

        return $row === null ? null : Photo::fromArray($row);
    }

    public function findByProperty(int $propertyId): array
    {
        $photos = array_map(
            Photo::fromArray(...),
            $this->db->select(self::TABLE, fn (array $r): bool => $r['property_id'] === $propertyId)
        );
        usort($photos, fn (Photo $a, Photo $b): int => [$a->sortOrder, $a->id] <=> [$b->sortOrder, $b->id]);

        return $photos;
    }

    public function save(Photo $photo): Photo
    {
        if ($photo->id === null) {
            return Photo::fromArray($this->db->insert(self::TABLE, $photo->toArray()));
        }
        $id = $photo->id;
        $this->db->update(self::TABLE, fn (array $r): bool => $r['id'] === $id, $photo->toArray());

        return $photo;
    }

    public function delete(int $id): void
    {
        $this->db->delete(self::TABLE, fn (array $r): bool => $r['id'] === $id);
    }
}
