<?php

namespace App\Modules\Ledger\Listeners;

use App\Modules\Booking\Contracts\PaymentLinkRequest;
use App\Modules\Booking\Events\PaymentCaptured;
use App\Modules\Ledger\Services\WalletTopupService;

/** PaymentCaptured for a wallet top-up: credit the franchise (spec §9). */
final class CreditWalletTopup
{
    public function __construct(private readonly WalletTopupService $topups) {}

    public function handle(PaymentCaptured $event): void
    {
        if ($event->purpose === PaymentLinkRequest::WALLET_TOPUP) {
            $this->topups->applyCapturedPayment($event);
        }
    }
}
