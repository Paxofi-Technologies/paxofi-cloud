<?php

declare(strict_types=1);

namespace PaxofiCloud\Tests\Unit\Domain\Payments;

use PaxofiCloud\Domain\Orders\InvalidTransition;
use PaxofiCloud\Domain\Payments\PaymentAttemptState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** SRS §5.2, PAY-004; THREAT-MODEL-PAYMENTS.md PY-5, PY-9. */
final class PaymentAttemptStateTest extends TestCase
{
    public function testTheListedTransitionsAreAllowed(): void
    {
        self::assertSame(PaymentAttemptState::Pending, PaymentAttemptState::Initiated->to(PaymentAttemptState::Pending));
        self::assertSame(PaymentAttemptState::Failed, PaymentAttemptState::Initiated->to(PaymentAttemptState::Failed));
        self::assertSame(PaymentAttemptState::Succeeded, PaymentAttemptState::Pending->to(PaymentAttemptState::Succeeded));
        // PY-9: the mark-paid update accepts initiated, pending and unknown.
        self::assertSame(PaymentAttemptState::Succeeded, PaymentAttemptState::Initiated->to(PaymentAttemptState::Succeeded));
        self::assertSame(PaymentAttemptState::Succeeded, PaymentAttemptState::Unknown->to(PaymentAttemptState::Succeeded));
        self::assertSame(PaymentAttemptState::Abandoned, PaymentAttemptState::Pending->to(PaymentAttemptState::Abandoned));
        self::assertSame(PaymentAttemptState::Refunded, PaymentAttemptState::Succeeded->to(PaymentAttemptState::Refunded));
    }

    /** @return iterable<string, array{PaymentAttemptState, PaymentAttemptState}> */
    public static function refused(): iterable
    {
        yield 'failed is final' => [PaymentAttemptState::Failed, PaymentAttemptState::Pending];
        yield 'a success is never undone' => [PaymentAttemptState::Succeeded, PaymentAttemptState::Failed];
        yield 'a mismatch never becomes paid' => [PaymentAttemptState::AmountMismatch, PaymentAttemptState::Succeeded];
        yield 'abandoned stays abandoned' => [PaymentAttemptState::Abandoned, PaymentAttemptState::Succeeded];
        yield 'no refund before success' => [PaymentAttemptState::Pending, PaymentAttemptState::Refunded];
        yield 'no way back to initiated' => [PaymentAttemptState::Pending, PaymentAttemptState::Initiated];
    }

    #[DataProvider('refused')]
    public function testOtherTransitionsThrow(PaymentAttemptState $from, PaymentAttemptState $to): void
    {
        $this->expectException(InvalidTransition::class);
        $from->to($to);
    }

    public function testOpenStatesAreTheOnesStillWaiting(): void
    {
        $open = array_values(array_filter(PaymentAttemptState::cases(), static fn (PaymentAttemptState $s): bool => $s->isOpen()));

        self::assertSame([PaymentAttemptState::Initiated, PaymentAttemptState::Pending, PaymentAttemptState::Unknown], $open);
    }
}
