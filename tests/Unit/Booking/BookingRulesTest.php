<?php

namespace Tests\Unit\Booking;

use App\Modules\Booking\Domain\DiscountAllocator;
use App\Modules\Booking\Domain\InvoiceStatusRule;
use App\Modules\Booking\Domain\PaymentRule;
use App\Modules\Booking\Enums\InvoicePaymentStatus;
use App\Modules\Booking\Enums\OrderSource;
use App\Modules\Shared\Money\Money;
use PHPUnit\Framework\TestCase;

final class BookingRulesTest extends TestCase
{
    public function test_a_discount_is_spread_in_proportion_and_adds_up_exactly(): void
    {
        $shares = DiscountAllocator::allocate(Money::fromString('100.00'), [
            Money::fromString('350.00'),
            Money::fromString('599.00'),
            Money::fromString('51.00'),
        ]);

        $this->assertSame(['35.00', '59.90', '5.10'], array_map('strval', $shares));
    }

    public function test_rounding_leftovers_go_to_the_lines_that_lost_most(): void
    {
        $shares = DiscountAllocator::allocate(Money::fromString('0.10'), array_fill(0, 3, Money::fromString('100.00')));

        $this->assertSame(10, array_sum(array_map(fn (Money $share) => $share->paise(), $shares)));
        $this->assertSame(['0.04', '0.03', '0.03'], array_map('strval', $shares));
    }

    public function test_discount_approval_kicks_in_above_the_limit(): void
    {
        $gross = Money::fromString('1000.00');

        $this->assertFalse(DiscountAllocator::needsApproval(Money::fromString('100.00'), $gross, '10'));
        $this->assertTrue(DiscountAllocator::needsApproval(Money::fromString('100.01'), $gross, '10'));
    }

    public function test_when_an_order_is_confirmed(): void
    {
        $total = Money::fromString('600.00');
        $none = Money::zero();

        $this->assertTrue(PaymentRule::isMet(OrderSource::B2b, true, $total, $none, '100'));
        $this->assertTrue(PaymentRule::isMet(OrderSource::HomeCollection, false, $total, $none, '100'));
        $this->assertFalse(PaymentRule::isMet(OrderSource::Online, false, $total, Money::fromString('599.99'), '0'));
        $this->assertFalse(PaymentRule::isMet(OrderSource::WalkIn, false, $total, Money::fromString('100.00'), '100'));
        $this->assertTrue(PaymentRule::isMet(OrderSource::WalkIn, false, $total, Money::fromString('300.00'), '50'));
    }

    public function test_invoice_status_follows_the_amounts(): void
    {
        $total = Money::fromString('500.00');

        $this->assertSame(InvoicePaymentStatus::Unpaid, InvoiceStatusRule::statusFor($total, Money::zero(), false, false));
        $this->assertSame(InvoicePaymentStatus::PartiallyPaid, InvoiceStatusRule::statusFor($total, Money::fromString('100'), false, false));
        $this->assertSame(InvoicePaymentStatus::Paid, InvoiceStatusRule::statusFor($total, $total, false, false));
        $this->assertSame(InvoicePaymentStatus::PartiallyRefunded, InvoiceStatusRule::statusFor($total, Money::fromString('300'), true, false));
        $this->assertSame(InvoicePaymentStatus::Refunded, InvoiceStatusRule::statusFor($total, Money::zero(), true, false));
        $this->assertSame(InvoicePaymentStatus::Credit, InvoiceStatusRule::statusFor($total, Money::zero(), false, true));
    }
}
