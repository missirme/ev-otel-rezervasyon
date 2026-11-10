<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ForbiddenException;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Model\Photo;
use App\Model\Property;
use App\Model\PropertyStatus;
use App\Repository\BookableUnitRepositoryInterface;
use App\Repository\PhotoRepositoryInterface;
use App\Repository\PropertyRepositoryInterface;
use App\Repository\TransactionManagerInterface;
use finfo;

/**
 * Resim yükleme (FR-46, NFR-19):
 *  - yalnızca JPEG, PNG, WebP; uzantı ve gerçek MIME türü birlikte doğrulanır (sahte uzantı reddedilir)
 *  - en çok 2 MB; dosya adı sunucuda rastgele üretilir
 *  - dosyalar web kökünün dışında (storage/uploads) tutulur, betik olarak çalıştırılamaz
 *  - başkasının ilanına yükleme reddedilir
 */
final class PhotoService
{
    private const ALLOWED = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];
    private const MAX_PER_PROPERTY = 12;

    public function __construct(
        private readonly PhotoRepositoryInterface $photos,
        private readonly PropertyRepositoryInterface $properties,
        private readonly BookableUnitRepositoryInterface $units,
        private readonly TransactionManagerInterface $tx,
        private readonly string $uploadDir,
        private readonly int $maxBytes,
        private readonly bool $requireHttpUpload = true,
    ) {
    }

    /** @return list<Photo> */
    public function forProperty(int $propertyId): array
    {
        return $this->photos->findByProperty($propertyId);
    }

    /**
     * @param array<string, mixed> $file $_FILES['foto'] girdisi (controller iletir)
     */
    public function upload(int $ownerId, int $propertyId, array $file, ?int $unitId = null): Photo
    {
        $property = $this->owned($ownerId, $propertyId);
        if ($unitId !== null && $this->units->findById($unitId)?->propertyId !== $propertyId) {
            throw ValidationException::single('birim', 'Seçilen oda bu ilana ait değil.');
        }
        $error = is_int($file['error'] ?? null) ? $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw ValidationException::single('foto', 'Dosya en çok 2 MB olabilir.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw ValidationException::single('foto', 'Lütfen bir resim dosyası seçin.');
        }
        $tmp = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        $originalName = is_string($file['name'] ?? null) ? $file['name'] : '';
        if ($tmp === '' || !is_file($tmp) || ($this->requireHttpUpload && !is_uploaded_file($tmp))) {
            throw ValidationException::single('foto', 'Dosya yüklenemedi, lütfen tekrar deneyin.');
        }
        $size = (int) filesize($tmp);
        if ($size <= 0 || $size > $this->maxBytes) {
            throw ValidationException::single('foto', 'Dosya en çok 2 MB olabilir.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED[$mime])) {
            throw ValidationException::single('foto', 'Yalnızca JPEG, PNG veya WebP resim yüklenebilir.');
        }
        if (!in_array($extension, self::ALLOWED[$mime], true)) {
            throw ValidationException::single('foto', 'Dosya uzantısı içeriğiyle uyuşmuyor.');
        }
        if (@getimagesize($tmp) === false) {
            throw ValidationException::single('foto', 'Dosya geçerli bir resim değil.');
        }
        if (count($this->photos->findByProperty($propertyId)) >= self::MAX_PER_PROPERTY) {
            throw ValidationException::single('foto', 'Bir ilana en çok ' . self::MAX_PER_PROPERTY . ' resim yüklenebilir.');
        }

        $fileName = bin2hex(random_bytes(16)) . '.' . self::ALLOWED[$mime][0];
        if (!is_dir($this->uploadDir) && !@mkdir($this->uploadDir, 0775, true) && !is_dir($this->uploadDir)) {
            throw ValidationException::single('foto', 'Yükleme klasörü oluşturulamadı.');
        }
        $target = $this->uploadDir . DIRECTORY_SEPARATOR . $fileName;
        $moved = $this->requireHttpUpload ? move_uploaded_file($tmp, $target) : copy($tmp, $target);
        if (!$moved) {
            throw ValidationException::single('foto', 'Dosya kaydedilemedi.');
        }

        try {
            return $this->tx->transactional(function () use ($property, $fileName, $mime, $size, $unitId): Photo {
                $existing = $this->photos->findByProperty((int) $property->id);
                $order = $existing === [] ? 1 : max(array_map(fn (Photo $p): int => $p->sortOrder, $existing)) + 1;

                return $this->photos->save(new Photo(null, (int) $property->id, $unitId, $fileName, $mime, $size, $order));
            });
        } catch (\Throwable $e) {
            @unlink($target);
            throw $e;
        }
    }

    /** Sıralama: $direction -1 yukarı, +1 aşağı. */
    public function move(int $ownerId, int $photoId, int $direction): void
    {
        $this->tx->transactional(function () use ($ownerId, $photoId, $direction): void {
            $photo = $this->photos->findById($photoId) ?? throw new NotFoundException('Resim bulunamadı.');
            $this->owned($ownerId, $photo->propertyId);
            $list = $this->photos->findByProperty($photo->propertyId);
            $index = array_search($photoId, array_map(fn (Photo $p): ?int => $p->id, $list), true);
            $swapIndex = is_int($index) ? $index + ($direction < 0 ? -1 : 1) : -1;
            if (!is_int($index) || !isset($list[$swapIndex])) {
                return;
            }
            // Sıra numaralarını yeniden yaz ve iki resmin yerini değiştir
            [$list[$index], $list[$swapIndex]] = [$list[$swapIndex], $list[$index]];
            foreach ($list as $i => $item) {
                $item->sortOrder = $i + 1;
                $this->photos->save($item);
            }
        });
    }

    public function delete(int $ownerId, int $photoId): void
    {
        $fileName = $this->tx->transactional(function () use ($ownerId, $photoId): string {
            $photo = $this->photos->findById($photoId) ?? throw new NotFoundException('Resim bulunamadı.');
            $this->owned($ownerId, $photo->propertyId);
            $this->photos->delete($photoId);

            return $photo->fileName;
        });
        @unlink($this->uploadDir . DIRECTORY_SEPARATOR . basename($fileName));
    }

    /**
     * Görüntüleme: kaldırılmış ilanların resimleri sunulmaz.
     *
     * @return array{path: string, mime: string}
     */
    public function file(int $photoId): array
    {
        $photo = $this->photos->findById($photoId) ?? throw new NotFoundException('Resim bulunamadı.');
        $property = $this->properties->findById($photo->propertyId);
        if ($property === null || $property->status === PropertyStatus::Kaldirildi) {
            throw new NotFoundException('Resim bulunamadı.');
        }
        $path = $this->uploadDir . DIRECTORY_SEPARATOR . basename($photo->fileName);
        if (!is_file($path)) {
            throw new NotFoundException('Resim dosyası bulunamadı.');
        }

        return ['path' => $path, 'mime' => $photo->mimeType];
    }

    private function owned(int $ownerId, int $propertyId): Property
    {
        $property = $this->properties->findById($propertyId) ?? throw new NotFoundException('İlan bulunamadı.');
        if ($property->ownerId !== $ownerId) {
            throw new ForbiddenException('Başkasının ilanına resim yükleyemezsiniz.');
        }

        return $property;
    }
}
