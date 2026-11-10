<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ForbiddenException;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Model\ModerationStatus;
use App\Model\ReservationStatus;
use App\Model\Review;
use App\Repository\BookableUnitRepositoryInterface;
use App\Repository\PropertyRepositoryInterface;
use App\Repository\ReservationRepositoryInterface;
use App\Repository\ReviewRepositoryInterface;
use App\Repository\TransactionManagerInterface;
use App\Repository\UserRepositoryInterface;
use App\Support\ClockInterface;
use App\Support\Paginator;
use App\Support\Text;

/** Yorum ve puan (FR-24) ile yönetici denetimi (FR-25). */
final class ReviewService
{
    public function __construct(
        private readonly ReviewRepositoryInterface $reviews,
        private readonly ReservationRepositoryInterface $reservations,
        private readonly BookableUnitRepositoryInterface $units,
        private readonly PropertyRepositoryInterface $properties,
        private readonly UserRepositoryInterface $users,
        private readonly AuditService $audit,
        private readonly TransactionManagerInterface $tx,
        private readonly ClockInterface $clock,
    ) {
    }

    /** BR-09, BR-21: yalnızca Tamamlandı rezervasyona, en çok bir yorum; puan 1-5 tam sayı. */
    public function create(int $customerId, string $reservationNumber, int $rating, string $comment): Review
    {
        $comment = trim($comment);
        $errors = [];
        if ($rating < 1 || $rating > 5) {
            $errors['puan'] = 'Puan 1 ile 5 arasında bir tam sayı olmalıdır.';
        }
        if (Text::length($comment) < 10 || Text::length($comment) > 1000) {
            $errors['yorum'] = 'Yorum 10 ile 1000 karakter arasında olmalıdır.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->tx->transactional(function () use ($customerId, $reservationNumber, $rating, $comment): Review {
            $reservation = $this->reservations->findByNumber($reservationNumber) ?? throw new NotFoundException('Rezervasyon bulunamadı.');
            if ($reservation->customerId !== $customerId) {
                throw new ForbiddenException('Yalnızca kendi konaklamanızı değerlendirebilirsiniz.');
            }
            if ($reservation->status !== ReservationStatus::Tamamlandi) {
                throw ValidationException::single('yorum', 'Yalnızca tamamlanmış konaklamalar değerlendirilebilir.');
            }
            if ($this->reviews->findByReservation((int) $reservation->id) !== null) {
                throw ValidationException::single('yorum', 'Bu konaklama için zaten yorum yaptınız.');
            }

            return $this->reviews->save(new Review(null, (int) $reservation->id, $customerId, $rating, $comment, ModerationStatus::Yayinda, $this->clock->now()));
        });
    }

    /**
     * @return Paginator<array{review: Review, author: string, property: string, number: string}>
     */
    public function adminList(?ModerationStatus $status, int $page, int $perPage): Paginator
    {
        $rows = [];
        foreach ($this->reviews->all() as $review) {
            if ($status !== null && $review->moderationStatus !== $status) {
                continue;
            }
            $reservation = $this->reservations->findById($review->reservationId);
            $unit = $reservation !== null ? $this->units->findById($reservation->unitId) : null;
            $property = $unit !== null ? $this->properties->findById($unit->propertyId) : null;
            $rows[] = [
                'review' => $review,
                'author' => $this->users->findById($review->customerId)?->fullName() ?? '-',
                'property' => $property->name ?? '-',
                'number' => $reservation->number ?? '-',
            ];
        }

        return new Paginator($rows, $page, $perPage);
    }

    /** Denetim kararı neden ve zamanla kaydedilir (BR-10). */
    public function moderate(int $adminId, int $reviewId, ModerationStatus $status, string $reason): Review
    {
        $reason = trim($reason);
        if (Text::length($reason) < 5 || Text::length($reason) > 500) {
            throw ValidationException::single('neden', 'Neden 5-500 karakter olmalıdır.');
        }

        return $this->tx->transactional(function () use ($adminId, $reviewId, $status, $reason): Review {
            $review = $this->reviews->findById($reviewId) ?? throw new NotFoundException('Yorum bulunamadı.');
            $old = $review->moderationStatus->value;
            $review->moderationStatus = $status;
            $this->reviews->save($review);
            $this->audit->log($adminId, 'review', $reviewId, 'yorum_denetimi', $old, $status->value, $reason);

            return $review;
        });
    }
}
