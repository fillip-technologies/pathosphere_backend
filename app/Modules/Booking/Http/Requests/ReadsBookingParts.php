<?php

namespace App\Modules\Booking\Http\Requests;

use App\Modules\Booking\Enums\PaymentMode;
use App\Modules\Booking\Services\DeskPayment;
use App\Modules\Catalogue\Domain\QuoteRequestItem;
use App\Modules\Shared\Money\Money;

/** Shared rules and readers for the items, discount and payment parts of booking requests. */
trait ReadsBookingParts
{
    /** Modes a front desk or phlebotomist can take in person. */
    private const DESK_PAYMENT_MODES = ['cash', 'card', 'upi'];

    /** @return array<string, mixed> */
    protected function bookingPartRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.test_id' => ['nullable', 'uuid', 'required_without:items.*.package_id', 'prohibits:items.*.package_id'],
            'items.*.package_id' => ['nullable', 'uuid', 'required_without:items.*.test_id'],
            'discount' => ['sometimes', 'nullable', 'array'],
            'discount.amount' => ['required_with:discount', 'decimal:0,2', 'min:0'],
            'discount.reason' => ['nullable', 'string', 'max:100'],
            'payment' => ['sometimes', 'nullable', 'array'],
            'payment.mode' => ['required_with:payment', 'in:'.implode(',', self::DESK_PAYMENT_MODES)],
            'payment.amount' => ['required_with:payment', 'decimal:0,2', 'gt:0'],
            'payment.transaction_id' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return list<QuoteRequestItem> */
    public function quoteItems(): array
    {
        return array_values(array_map(
            fn (array $item): QuoteRequestItem => isset($item['test_id'])
                ? QuoteRequestItem::test($item['test_id'])
                : QuoteRequestItem::package($item['package_id']),
            $this->validated('items'),
        ));
    }

    public function discountAmount(): Money
    {
        $amount = $this->validated('discount.amount');

        return $amount === null ? Money::zero() : Money::fromString((string) $amount);
    }

    public function discountReason(): ?string
    {
        return $this->validated('discount.reason');
    }

    public function deskPayment(): ?DeskPayment
    {
        $payment = $this->validated('payment');

        if (! is_array($payment)) {
            return null;
        }

        return new DeskPayment(PaymentMode::from($payment['mode']), Money::fromString((string) $payment['amount']), $payment['transaction_id'] ?? null);
    }
}
