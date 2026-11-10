<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ForbiddenException;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Model\Complaint;
use App\Model\ComplaintStatus;
use App\Model\PropertyStatus;
use App\Model\Role;
use App\Model\UserStatus;
use App\Repository\BookableUnitRepositoryInterface;
use App\Repository\ComplaintRepositoryInterface;
use App\Repository\PropertyRepositoryInterface;
use App\Repository\ReservationRepositoryInterface;
use App\Repository\TransactionManagerInterface;
use App\Repository\UserRepositoryInterface;
use App\Support\ClockInterface;
use App\Support\Paginator;
use App\Support\Text;

/**
 * Şikâyet oluşturma (FR-27) ve yönetici incelemesi.
 *
 * Akış: kullanıcı gönderir → şikâyet "Yeni" durumuyla yönetim ekibinin Şikâyetler kutusuna
 * (Yönetim → Şikâyetler) düşer ve her aktif yöneticiye bildirim gider → yönetici durumu veya notu
 * günceller → şikâyet sahibine bildirim gider; sahibi şikâyetini Şikâyetlerim sayfasından izler.
 */
final class ComplaintService
{
    public function __construct(
        private readonly ComplaintRepositoryInterface $complaints,
        private readonly PropertyRepositoryInterface $properties,
        private readonly ReservationRepositoryInterface $reservations,
        private readonly BookableUnitRepositoryInterface $units,
        private readonly UserRepositoryInterface $users,
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
        private readonly TransactionManagerInterface $tx,
        private readonly ClockInterface $clock,
    ) {
    }

    public function create(int $senderId, ?int $propertyId, string $reservationNumber, string $description): Complaint
    {
        $description = trim($description);
        if (Text::length($description) < 10 || Text::length($description) > 2000) {
            throw ValidationException::single('aciklama', 'Açıklama 10 ile 2000 karakter arasında olmalıdır.');
        }

        return $this->tx->transactional(function () use ($senderId, $propertyId, $reservationNumber, $description): Complaint {
            $reservationId = null;
            if (trim($reservationNumber) !== '') {
                $reservation = $this->reservations->findByNumber(trim($reservationNumber));
                if ($reservation === null) {
                    throw ValidationException::single('rezervasyon', 'Bu numarayla bir rezervasyon bulunamadı.');
                }
                if ($reservation->customerId !== $senderId) {
                    throw new ForbiddenException('Yalnızca kendi rezervasyonunuz hakkında şikâyet oluşturabilirsiniz.');
                }
                $reservationId = $reservation->id;
                $propertyId ??= $this->units->findById($reservation->unitId)?->propertyId;
            }
            if ($propertyId !== null && $this->properties->findById($propertyId) === null) {
                throw new NotFoundException('İlan bulunamadı.');
            }
            if ($propertyId === null && $reservationId === null) {
                throw ValidationException::single('ilan', 'Şikâyetin hangi ilan veya rezervasyonla ilgili olduğunu seçin.');
            }

            $complaint = $this->complaints->save(new Complaint(null, $senderId, $propertyId, $reservationId, $description, ComplaintStatus::Yeni, null, $this->clock->now()));
            $message = sprintf('Yeni şikâyet #%d: %s. Yönetim → Şikâyetler sayfasından inceleyin.', $complaint->id, $this->subject($complaint));
            foreach ($this->users->all() as $user) {
                if ($user->role === Role::Yonetici && $user->status === UserStatus::Aktif && $user->id !== null) {
                    $this->notifications->notify($user->id, 'sikayet', $message);
                }
            }

            return $complaint;
        });
    }

    /**
     * Şikâyetlerim: kullanıcının kendi şikâyetleri, en yenisi başta.
     *
     * @return list<array{complaint: Complaint, property: string, number: string}>
     */
    public function forSender(int $senderId): array
    {
        $rows = [];
        foreach ($this->complaints->all() as $complaint) {
            if ($complaint->senderId === $senderId) {
                $rows[] = ['complaint' => $complaint, 'property' => $this->propertyName($complaint), 'number' => $this->reservationNumber($complaint)];
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$b['complaint']->createdAt, $b['complaint']->id] <=> [$a['complaint']->createdAt, $a['complaint']->id]);

        return $rows;
    }

    /** @return array<int, string> şikâyet formundaki ilan seçenekleri: yayındaki ilanlar (ad · şehir) */
    public function propertyOptions(): array
    {
        $options = [];
        foreach ($this->properties->findByStatus(PropertyStatus::Aktif) as $property) {
            $options[(int) $property->id] = $property->name . ' · ' . $property->city;
        }
        asort($options);

        return $options;
    }

    /**
     * @return Paginator<array{complaint: Complaint, sender: string, property: string, number: string}>
     */
    public function adminList(?ComplaintStatus $status, int $page, int $perPage): Paginator
    {
        $rows = [];
        foreach ($this->complaints->all() as $complaint) {
            if ($status !== null && $complaint->status !== $status) {
                continue;
            }
            $rows[] = [
                'complaint' => $complaint,
                'sender' => $this->users->findById($complaint->senderId)?->fullName() ?? '-',
                'property' => $this->propertyName($complaint),
                'number' => $this->reservationNumber($complaint),
            ];
        }

        return new Paginator($rows, $page, $perPage);
    }

    public function update(int $adminId, int $complaintId, ComplaintStatus $status, string $note): Complaint
    {
        return $this->tx->transactional(function () use ($adminId, $complaintId, $status, $note): Complaint {
            $complaint = $this->complaints->findById($complaintId) ?? throw new NotFoundException('Şikâyet bulunamadı.');
            $old = $complaint->status->value;
            $complaint->status = $status;
            if (Text::length($note) > 2000) {
                throw ValidationException::single('not', 'Yönetici notu en çok 2000 karakter olabilir.');
            }
            $complaint->adminNote = trim($note) !== '' ? trim($note) : $complaint->adminNote;
            $this->complaints->save($complaint);
            $this->audit->log($adminId, 'complaint', $complaintId, 'sikayet_guncellendi', $old, $status->value, $complaint->adminNote);
            if ($old !== $status->value || trim($note) !== '') {
                $this->notifications->notify($complaint->senderId, 'sikayet', sprintf(
                    'Şikâyetiniz #%d güncellendi: %s.%s',
                    $complaint->id,
                    $status->label(),
                    $complaint->adminNote !== null ? ' Yönetici notu: ' . $complaint->adminNote : ''
                ));
            }

            return $complaint;
        });
    }

    private function subject(Complaint $complaint): string
    {
        $number = $this->reservationNumber($complaint);

        return $this->propertyName($complaint) . ($number !== '-' ? ' · ' . $number : '');
    }

    private function propertyName(Complaint $complaint): string
    {
        return $complaint->propertyId !== null ? ($this->properties->findById($complaint->propertyId)->name ?? '-') : '-';
    }

    private function reservationNumber(Complaint $complaint): string
    {
        return $complaint->reservationId !== null ? ($this->reservations->findById($complaint->reservationId)->number ?? '-') : '-';
    }
}
