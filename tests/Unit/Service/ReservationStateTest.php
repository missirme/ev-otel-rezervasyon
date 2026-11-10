<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Model\ReservationStatus as S;
use App\Service\State\ReservationState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Group;

/** T-02: SRS 4.3 durum makinesinin tüm geçerli ve geçersiz geçişleri. */
#[Group('T-02')]
#[Group('FR-15')]
final class ReservationStateTest extends TestCase
{
    private const ALLOWED = [
        'beklemede' => ['onaylandi', 'reddedildi', 'iptal_edildi', 'suresi_doldu'],
        'onaylandi' => ['iptal_edildi', 'tamamlandi'],
        'iptal_edildi' => [],
        'reddedildi' => [],
        'suresi_doldu' => [],
        'tamamlandi' => [],
    ];

    /** @return iterable<string, array{S, S, bool}> */
    public static function allPairs(): iterable
    {
        foreach (S::cases() as $from) {
            foreach (S::cases() as $to) {
                yield $from->value . ' -> ' . $to->value => [$from, $to, in_array($to->value, self::ALLOWED[$from->value], true)];
            }
        }
    }

    #[DataProvider('allPairs')]
    public function testTransitionTable(S $from, S $to, bool $allowed): void
    {
        self::assertSame($allowed, ReservationState::of($from)->canTransitionTo($to));
    }

    #[Group('BR-12')]
    public function testOnlyPendingAndConfirmedHoldNights(): void
    {
        foreach (S::cases() as $status) {
            $expected = in_array($status, [S::Beklemede, S::Onaylandi], true);
            self::assertSame($expected, ReservationState::of($status)->holdsNights(), $status->value);
        }
    }

    public function testFinalStatesHaveNoExit(): void
    {
        foreach ([S::IptalEdildi, S::Reddedildi, S::SuresiDoldu, S::Tamamlandi] as $status) {
            self::assertTrue(ReservationState::of($status)->isFinal());
        }
        self::assertFalse(ReservationState::of(S::Beklemede)->isFinal());
    }
}
