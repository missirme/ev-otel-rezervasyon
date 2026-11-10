<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use App\Core\Kernel;
use App\Core\Request;
use App\Exception\ValidationException;
use App\Model\Complaint;
use App\Model\ComplaintStatus;
use App\Model\Notification;
use App\Model\Role;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\AppTestCase;

/**
 * FR-27: şikâyet nereye düşer? Yönetim ekibinin Şikâyetler kutusuna; aktif yöneticilere bildirim gider.
 * Yönetici durumu veya notu güncelleyince şikâyet sahibi bildirim alır ve Şikâyetlerim sayfasından izler.
 */
#[Group('FR-27')]
final class ComplaintFlowTest extends AppTestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    /** Depodaki güncel şikâyet kaydı. */
    private function stored(int $id): Complaint
    {
        $complaint = $this->c->complaints()->findById($id);
        self::assertNotNull($complaint);

        return $complaint;
    }

    /** @return list<string> */
    private function messagesOf(int $userId): array
    {
        return array_map(static fn (Notification $n): string => $n->message, $this->c->notifications()->findByUser($userId));
    }

    #[Group('FR-26')]
    public function testComplaintLandsInTheAdminInboxAndNotifiesEveryActiveAdmin(): void
    {
        $guest = $this->user();
        $host = $this->user(Role::IlanSahibi);
        $admins = [$this->user(Role::Yonetici), $this->user(Role::Yonetici)];
        $property = $this->property((int) $host->id);

        $complaint = $this->c->complaintService()->create((int) $guest->id, (int) $property->id, '', 'Odadaki klima çalışmıyordu, kimse ilgilenmedi.');

        $inbox = $this->c->complaintService()->adminList(ComplaintStatus::Yeni, 1, 20)->items;
        self::assertSame([$complaint->id], array_map(static fn (array $row): ?int => $row['complaint']->id, $inbox), 'Yönetim → Şikâyetler kutusunda Yeni durumuyla görünür');
        foreach ($admins as $admin) {
            $messages = $this->messagesOf((int) $admin->id);
            self::assertCount(1, $messages);
            self::assertStringContainsString('Yeni şikâyet #' . $complaint->id, $messages[0]);
            self::assertStringContainsString($property->name, $messages[0]);
        }
        self::assertSame([], $this->messagesOf((int) $host->id), 'Şikâyet ilan sahibine değil yönetime gider');
    }

    #[Group('FR-26')]
    public function testAdminAnswerReachesTheSenderAndMyComplaintsShowsOnlyOwnComplaints(): void
    {
        $guest = $this->user();
        $other = $this->user();
        $admin = $this->user(Role::Yonetici);
        $property = $this->property((int) $this->user(Role::IlanSahibi)->id);
        $complaint = $this->c->complaintService()->create((int) $guest->id, (int) $property->id, '', 'İlandaki fotoğraflar gerçeği yansıtmıyor.');
        $this->c->complaintService()->create((int) $other->id, (int) $property->id, '', 'Başka bir kullanıcının şikâyeti.');

        $this->c->complaintService()->update((int) $admin->id, (int) $complaint->id, ComplaintStatus::Inceleniyor, 'İlan sahibine ulaşıyoruz.');

        $mine = $this->c->complaintService()->forSender((int) $guest->id);
        self::assertCount(1, $mine, 'Şikâyetlerim yalnızca kendi şikâyetlerini gösterir');
        self::assertSame(ComplaintStatus::Inceleniyor, $mine[0]['complaint']->status);
        self::assertSame($property->name, $mine[0]['property']);
        $messages = $this->messagesOf((int) $guest->id);
        self::assertCount(1, $messages);
        self::assertStringContainsString('#' . $complaint->id . ' güncellendi: İnceleniyor', $messages[0]);
        self::assertStringContainsString('İlan sahibine ulaşıyoruz.', $messages[0]);

        $this->c->complaintService()->update((int) $admin->id, (int) $complaint->id, ComplaintStatus::Inceleniyor, '');
        self::assertCount(1, $this->messagesOf((int) $guest->id), 'Değişiklik yoksa yeniden bildirim gitmez');
    }

    public function testComplaintMustNameAPropertyOrReservation(): void
    {
        try {
            $this->c->complaintService()->create((int) $this->user()->id, null, '', 'İlan seçilmeden gönderilen şikâyet.');
            self::fail('İlan veya rezervasyon olmadan şikâyet kaydedilmemeli');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('ilan', $e->errors());
        }
    }

    public function testSubmittingFromTheWebRedirectsToMyComplaints(): void
    {
        $_SESSION = [];
        (new Kernel($this->c))->handle(new Request('GET', '/')); // örnek veri
        $user = $this->c->users()->findByEmail('ali@evotel.test');
        self::assertNotNull($user);
        $_SESSION['user_id'] = $user->id;
        $_SESSION['_last_activity'] = $this->clock->now()->getTimestamp();
        $kernel = new Kernel(new Container($this->settings(), $this->clock));

        $form = $kernel->handle(new Request('GET', '/sikayet'));
        self::assertSame(200, $form->status);
        self::assertStringContainsString('Şikâyetiniz nereye gider?', $form->body);
        self::assertStringContainsString('Karadeniz Otel · Trabzon', $form->body, 'İlan listeden seçilir');

        $response = $kernel->handle(new Request('POST', '/sikayet', [], [
            '_csrf' => $_SESSION['_csrf'] ?? '',
            'ilan' => '1',
            'rezervasyon' => '',
            'aciklama' => 'Resepsiyon geç saatte yanıt vermedi.',
        ]));
        self::assertSame(303, $response->status);
        self::assertSame('/sikayetlerim', $response->headers['Location']);

        $page = $kernel->handle(new Request('GET', '/sikayetlerim'));
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Şikâyetler kutusuna düştü', $page->body);
        self::assertStringContainsString('Resepsiyon geç saatte yanıt vermedi.', $page->body);
        self::assertStringContainsString('rozet-notr', $page->body, 'Yeni şikâyet durumu rozetle gösterilir');
    }

    /** Yönetici formundaki hata "beklenmeyen hata" sayfasına düşmez: anlaşılır mesajla aynı sayfaya dönülür, kayıt değişmez. */
    #[Group('NFR-06')]
    public function testAdminFormErrorsAreShownAsMessagesAndChangeNothing(): void
    {
        $_SESSION = [];
        (new Kernel($this->c))->handle(new Request('GET', '/')); // örnek veri
        $sender = $this->c->users()->findByEmail('ali@evotel.test');
        $admin = $this->c->users()->findByEmail('yonetici@evotel.test');
        self::assertNotNull($sender);
        self::assertNotNull($admin);
        $id = (int) $this->c->complaintService()->create((int) $sender->id, 1, '', 'Oda temiz değildi, havlular değiştirilmemişti.')->id;
        $_SESSION['user_id'] = $admin->id;
        $_SESSION['_last_activity'] = $this->clock->now()->getTimestamp();
        $kernel = new Kernel(new Container($this->settings(), $this->clock));
        $inbox = $kernel->handle(new Request('GET', '/yonetim/sikayetler'));
        self::assertSame(200, $inbox->status);
        self::assertStringContainsString('name="not" maxlength="2000"', $inbox->body, 'Tarayıcı da uzun notu baştan engeller');
        $send = fn (string $status, string $note) => $kernel->handle(new Request('POST', '/yonetim/sikayet/' . $id, [], [
            '_csrf' => $_SESSION['_csrf'] ?? '',
            'durum' => $status,
            'not' => $note,
        ]));

        $tooLong = $send('inceleniyor', str_repeat('a', 2001));
        self::assertSame(303, $tooLong->status, 'Uzun not 500 hatasına yol açmaz');
        self::assertSame('/yonetim/sikayetler', $tooLong->headers['Location']);
        self::assertStringContainsString('Yönetici notu en çok 2000 karakter olabilir.', $kernel->handle(new Request('GET', '/yonetim/sikayetler'))->body);
        self::assertSame(ComplaintStatus::Yeni, $this->stored($id)->status);

        $unknown = $send('yok', 'Not');
        self::assertSame(303, $unknown->status);
        self::assertStringContainsString('Geçersiz durum.', $kernel->handle(new Request('GET', '/yonetim/sikayetler'))->body);
        self::assertSame(ComplaintStatus::Yeni, $this->stored($id)->status, 'Bilinmeyen durum sessizce "İnceleniyor" yapılmaz');
        self::assertNull($this->stored($id)->adminNote);

        self::assertSame(303, $send('cozuldu', 'İlan sahibiyle görüşüldü.')->status);
        self::assertStringContainsString('Şikâyet güncellendi.', $kernel->handle(new Request('GET', '/yonetim/sikayetler'))->body);
        self::assertSame(ComplaintStatus::Cozuldu, $this->stored($id)->status);
        self::assertSame('İlan sahibiyle görüşüldü.', $this->stored($id)->adminNote);
    }
}
