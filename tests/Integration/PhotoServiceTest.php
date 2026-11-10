<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Kernel;
use App\Core\Request;
use App\Exception\ForbiddenException;
use App\Exception\ValidationException;
use App\Model\Role;
use App\Service\PhotoService;
use Tests\Support\AppTestCase;
use PHPUnit\Framework\Attributes\Group;

/** FR-46, NFR-19, AC-31: tür, boyut, sahte uzantı ve sahiplik denetimi. */
#[Group('FR-46')]
#[Group('NFR-19')]
#[Group('AC-31')]
final class PhotoServiceTest extends AppTestCase
{
    private PhotoService $photos;
    private int $ownerId;
    private int $propertyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->photos = new PhotoService($this->c->photos(), $this->c->properties(), $this->c->units(), $this->c->tx(), $this->dir . '/uploads', 2 * 1024 * 1024, false);
        $this->ownerId = (int) $this->user(Role::IlanSahibi)->id;
        $this->propertyId = (int) $this->property($this->ownerId)->id;
    }

    /** @return array<string, mixed> */
    private function file(string $name, string $content): array
    {
        $tmp = $this->dir . '/' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $content);

        return ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)];
    }

    private function png(): string
    {
        // 1x1 piksel PNG
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    public function testValidPngIsStoredWithRandomName(): void
    {
        $photo = $this->photos->upload($this->ownerId, $this->propertyId, $this->file('ev.png', $this->png()));

        self::assertSame('image/png', $photo->mimeType);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', $photo->fileName);
        self::assertFileExists($this->dir . '/uploads/' . $photo->fileName);
        self::assertNull($photo->unitId, 'Oda seçilmezse ilanın genel fotoğrafıdır');
    }

    /** Dosya web kökünün dışında durur; tarayıcıya ön denetleyici üzerinden, kendi türüyle sunulur (FR-09: ilan fotoğrafları). */
    #[Group('FR-09')]
    public function testUploadedPhotoIsServedThroughTheFrontController(): void
    {
        $photo = $this->photos->upload($this->ownerId, $this->propertyId, $this->file('ev.png', $this->png()));

        $_SESSION = [];
        $kernel = new Kernel($this->c);
        $served = $kernel->handle(new Request('GET', '/foto/' . $photo->id));
        $missing = $kernel->handle(new Request('GET', '/foto/999999'));
        $_SESSION = [];

        self::assertSame(200, $served->status);
        self::assertSame('image/png', $served->headers['Content-Type'] ?? null);
        self::assertSame($this->png(), $served->body, 'Yüklenen dosyanın kendisi döner');
        self::assertSame(404, $missing->status);
    }

    public function testPhotoCanBelongToAUnitOfTheSameProperty(): void
    {
        $unit = $this->unit($this->propertyId);
        $photo = $this->photos->upload($this->ownerId, $this->propertyId, $this->file('oda.png', $this->png()), (int) $unit->id);
        self::assertSame((int) $unit->id, $photo->unitId);

        $otherProperty = (int) $this->property($this->ownerId)->id;
        $this->expectException(ValidationException::class);
        $this->photos->upload($this->ownerId, $otherProperty, $this->file('oda.png', $this->png()), (int) $unit->id);
    }

    public function testFakeExtensionIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->photos->upload($this->ownerId, $this->propertyId, $this->file('ev.jpg', $this->png()));
    }

    public function testScriptDisguisedAsImageIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->photos->upload($this->ownerId, $this->propertyId, $this->file('kotu.png', '<?php echo "hack"; ?>'));
    }

    public function testFileLargerThanTwoMegabytesIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->photos->upload($this->ownerId, $this->propertyId, $this->file('buyuk.png', $this->png() . str_repeat('0', 2 * 1024 * 1024)));
    }

    public function testUploadToSomeoneElsesPropertyIsRejected(): void
    {
        $stranger = (int) $this->user(Role::IlanSahibi)->id;

        $this->expectException(ForbiddenException::class);
        $this->photos->upload($stranger, $this->propertyId, $this->file('ev.png', $this->png()));
    }
}
