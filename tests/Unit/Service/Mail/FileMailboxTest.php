<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Mail;

use App\Service\Dto\MailMessage;
use App\Service\Mail\FileMailbox;
use App\Service\Mail\FileMailer;
use App\Support\FrozenClock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\AppTestCase;

/** FR-04 (geliştirme): simüle posta kutusu FileMailer'ın yazdığı iletileri en yenisi başta okur; klasör dışına çıkılamaz. */
#[Group('FR-04')]
final class FileMailboxTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'evotel-kutu-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        AppTestCase::removeDirectory($this->dir);
    }

    public function testMessagesAreListedNewestFirstAndReadBack(): void
    {
        $clock = new FrozenClock('2026-11-01 10:00:00');
        $mailer = new FileMailer($this->dir, $clock);
        $mailer->send('ali@evotel.test', 'Parola sıfırlama', "Merhaba Ali,\nBağlantı: http://localhost/parola-sifirla/abc");
        $clock->advance('+5 minutes');
        $mailer->send('ayse@evotel.test', 'İkinci ileti', 'Gövde');
        file_put_contents($this->dir . '/notlar.txt', 'posta değil'); // biçime uymayan dosya listelenmez

        $box = new FileMailbox($this->dir, true);
        $messages = $box->latest();

        self::assertTrue($box->enabled());
        self::assertSame(['ayse@evotel.test', 'ali@evotel.test'], array_map(static fn (MailMessage $m): string => $m->to, $messages), 'En yeni ileti başta');
        $first = $box->find($messages[1]->id);
        self::assertNotNull($first);
        self::assertSame('Parola sıfırlama', $first->subject);
        self::assertSame("Merhaba Ali,\nBağlantı: http://localhost/parola-sifirla/abc", $first->body);
        self::assertSame('2026-11-01 10:00:00', $first->sentAt?->format('Y-m-d H:i:s'));
        self::assertCount(1, $box->latest(1), 'Sınır uygulanır');
    }

    public function testIdsThatAreNotMailFileNamesAreRejected(): void
    {
        (new FileMailer($this->dir, new FrozenClock('2026-11-01 10:00:00')))->send('ali@evotel.test', 'Konu', 'Gövde');
        $box = new FileMailbox($this->dir, true);

        foreach (['', '..', '../data/users', '..\\data\\users', 'notlar', "20261101-100000-abcdef\n", '20261101-100000-ABCDEF', '20261101-100000-abcdef/../../x'] as $id) {
            self::assertNull($box->find($id), 'Geçersiz kimlik: ' . json_encode($id));
        }
        self::assertNull($box->find('20261101-100000-000000'), 'Biçimi doğru ama olmayan ileti');
        self::assertSame([], (new FileMailbox($this->dir . '/yok', false))->latest(), 'Klasör yoksa boş liste');
    }
}
