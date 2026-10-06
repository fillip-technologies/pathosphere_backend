<?php

namespace App\Modules\Ledger\Services;

use App\Modules\Booking\Contracts\PaymentGateway;
use App\Modules\Booking\Contracts\PaymentLink;
use App\Modules\Booking\Contracts\PaymentLinkRequest;
use App\Modules\Booking\Events\PaymentCaptured;
use App\Modules\Ledger\Domain\PostingRules;
use App\Modules\Ledger\Enums\WalletTopupStatus;
use App\Modules\Ledger\Errors\LedgerError;
use App\Modules\Ledger\Models\WalletTopup;
use App\Modules\Ledger\StateMachines\WalletTopupStateMachine;
use App\Modules\Network\Enums\PartnerType;
use App\Modules\Network\Services\PartnerAccounts;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Context\Actor;
use App\Modules\Shared\Context\CurrentActor;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Franchise wallet top-ups (spec §5.1 step 6, §8 POST /wallet/topups): a
 * gateway payment link now, a ledger credit when the gateway's webhook
 * confirms the payment (PaymentCaptured). Never credited on the client's word.
 */
final class WalletTopupService
{
    public function __construct(
        private readonly PartnerAccounts $accounts,
        private readonly PaymentGateway $gateway,
        private readonly LedgerPostingService $postings,
        private readonly WalletTopupStateMachine $stateMachine,
        private readonly AuditLogger $auditLogger,
        private readonly CurrentScope $currentScope,
        private readonly CurrentActor $currentActor,
    ) {}

    /** @return array{WalletTopup, PaymentLink} */
    public function request(string $organizationId, string $franchiseId, Money $amount): array
    {
        $account = $this->accounts->find($organizationId, PartnerType::Franchise, $franchiseId);

        if ($account?->terms === null) {
            throw LedgerError::franchiseCannotTopUp();
        }

        $topup = new WalletTopup(['amount' => $amount, 'status' => WalletTopupStatus::Pending, 'gateway' => $this->gateway->name()]);
        $topup->id = $topup->newUniqueId();
        $topup->organization_id = $organizationId;
        $topup->franchise_id = $franchiseId;

        $link = $this->gateway->createPaymentLink(new PaymentLinkRequest(
            PaymentLinkRequest::WALLET_TOPUP,
            $topup->id,
            $account->code,
            "Wallet top-up for {$account->name}",
            $amount,
            $account->name,
            (string) $account->phone,
            $account->email,
            CarbonImmutable::now()->addHours((int) config('pathology.payments.link_expiry_hours')),
        ));

        DB::transaction(function () use ($topup, $link): void {
            $topup->payment_link_id = $link->linkId;
            $topup->link_expires_at = $link->expiresAt;
            $topup->save();
            $this->auditLogger->recordCreated('wallet_topup.create', $topup);
        });

        return [$topup, $link];
    }

    /**
     * The gateway confirmed the payment: credit what was actually paid, once.
     * Repeated webhooks find the top-up already paid and do nothing.
     */
    public function applyCapturedPayment(PaymentCaptured $payment): void
    {
        $topup = $this->currentScope->runAs(ScopeContext::system(), fn () => WalletTopup::query()->find($payment->referenceId));

        if ($topup === null) {
            return;
        }

        $this->currentActor->runAs(Actor::system($topup->organization_id), fn () => $this->currentScope->runAs(
            ScopeContext::system($topup->organization_id),
            fn () => DB::transaction(function () use ($topup, $payment): void {
                $topup = WalletTopup::query()->lockForUpdate()->findOrFail($topup->id);

                if ($topup->status !== WalletTopupStatus::Pending) {
                    return;
                }

                $this->stateMachine->transition($topup, WalletTopupStatus::Paid, [
                    'gateway_payment_id' => $payment->gatewayPaymentId,
                    'paid_at' => $payment->paidAt,
                    'amount' => $payment->amount,
                ]);

                $this->postings->post($topup->organization_id, PartnerType::Franchise, $topup->franchise_id, [
                    PostingRules::walletToppedUp($topup->id, $payment->amount),
                ]);
            }),
        ));
    }
}
