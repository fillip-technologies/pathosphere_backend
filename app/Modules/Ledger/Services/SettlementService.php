<?php

namespace App\Modules\Ledger\Services;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Booking\Services\B2bReceivables;
use App\Modules\Ledger\Domain\PostingRules;
use App\Modules\Ledger\Enums\SettlementDirection;
use App\Modules\Ledger\Enums\SettlementStatus;
use App\Modules\Ledger\Errors\LedgerError;
use App\Modules\Ledger\Models\Settlement;
use App\Modules\Ledger\Models\SettlementItem;
use App\Modules\Ledger\StateMachines\SettlementStateMachine;
use App\Modules\Network\Enums\PartnerType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * HQ Finance's review of a settlement (spec §5.6 step 4): approve, dispute,
 * and record the payment that settles it. The payment is a ledger row of its
 * own, linked to the settlement, so the account balance returns to what the
 * statement promised.
 */
final class SettlementService
{
    public function __construct(
        private readonly SettlementStateMachine $stateMachine,
        private readonly LedgerPostingService $postings,
        private readonly B2bReceivables $receivables,
    ) {}

    public function approve(StaffContext $staff, Settlement $settlement): Settlement
    {
        $this->stateMachine->transition($settlement, SettlementStatus::Approved, ['approved_by' => $staff->user()->id]);

        return $settlement;
    }

    public function dispute(Settlement $settlement, string $note): Settlement
    {
        $this->stateMachine->transition($settlement, SettlementStatus::Disputed, ['dispute_note' => $note]);

        return $settlement;
    }

    public function markSettled(Settlement $settlement, ?string $paymentReference): Settlement
    {
        $movesMoney = $settlement->direction !== SettlementDirection::Nil;

        if ($movesMoney && ($paymentReference === null || trim($paymentReference) === '')) {
            throw LedgerError::paymentReferenceRequired();
        }

        return DB::transaction(function () use ($settlement, $paymentReference, $movesMoney): Settlement {
            $settlement = Settlement::query()->lockForUpdate()->findOrFail($settlement->id);

            if ($movesMoney && $settlement->status === SettlementStatus::Approved) {
                $posting = PostingRules::settlementPaid($settlement->id, $settlement->settlement_no, $settlement->direction, $settlement->net_amount, (string) $paymentReference);
                $entries = $posting === null ? [] : $this->postings->post(
                    $settlement->organization_id,
                    $settlement->franchise_id !== null ? PartnerType::Franchise : PartnerType::B2bClient,
                    (string) ($settlement->franchise_id ?? $settlement->b2b_client_id),
                    [$posting],
                );

                foreach ($entries as $entry) {
                    SettlementItem::query()->firstOrCreate(['ledger_entry_id' => $entry->id], ['settlement_id' => $settlement->id]);
                }

                // A client's payment also pays the invoices it was billed on.
                if ($settlement->b2b_client_id !== null && $settlement->direction === SettlementDirection::PartnerPaysHq) {
                    $this->receivables->applySettlementPayment($settlement->organization_id, $settlement->b2b_client_id, $settlement->net_amount, (string) $paymentReference, $settlement->period_end);
                }
            }

            $this->stateMachine->transition($settlement, SettlementStatus::Settled, [
                'settled_at' => CarbonImmutable::now(),
                'payment_reference' => $movesMoney ? $paymentReference : null,
            ]);

            return $settlement;
        });
    }
}
