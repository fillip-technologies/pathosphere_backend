<?php

namespace App\Modules\Ledger;

use App\Modules\Booking\Contracts\PartnerChargePolicy;
use App\Modules\Booking\Events\PaymentCaptured;
use App\Modules\Ledger\Listeners\CreditWalletTopup;
use App\Modules\Ledger\Listeners\PostAgreementCharges;
use App\Modules\Ledger\Listeners\ReverseChargesForRejectedSample;
use App\Modules\Ledger\Services\LedgerPartnerCharges;
use App\Modules\Network\Events\AgreementSigned;
use App\Modules\Samples\Events\SampleRejected;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Partner ledger, wallets and settlements (spec §5.6, §7.8, §9 money events). */
final class LedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Booking posts partner charges through this policy, inside its own transaction.
        $this->app->bind(PartnerChargePolicy::class, LedgerPartnerCharges::class);
    }

    public function boot(): void
    {
        Event::listen(AgreementSigned::class, PostAgreementCharges::class);
        Event::listen(PaymentCaptured::class, CreditWalletTopup::class);
        Event::listen(SampleRejected::class, ReverseChargesForRejectedSample::class);
    }
}
