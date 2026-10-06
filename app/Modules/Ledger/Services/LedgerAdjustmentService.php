<?php

namespace App\Modules\Ledger\Services;

use App\Modules\Ledger\Domain\Posting;
use App\Modules\Ledger\Domain\ReferenceType;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Models\LedgerEntry;
use App\Modules\Network\Enums\PartnerType;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Manual corrections by HQ Finance (spec §8 POST /ledger/adjustments). A
 * correction is always a new row, never an edit; the reason comes from the
 * configured lookup list so reports can group by it (spec §6).
 */
final class LedgerAdjustmentService
{
    public function __construct(
        private readonly LedgerPostingService $postings,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** @param  'debit'|'credit'  $side  debit: the partner owes HQ more; credit: HQ owes the partner */
    public function post(string $organizationId, PartnerType $partnerType, string $partnerId, string $side, Money $amount, string $reason, ?string $note): LedgerEntry
    {
        $label = (string) config("pathology.ledger.adjustment_reasons.{$reason}");
        $narration = 'Adjustment: '.$label.($note !== null && $note !== '' ? " ({$note})" : '');

        $posting = $side === 'debit'
            ? Posting::debit(LedgerEntryType::Adjustment, $amount, $narration, ReferenceType::MANUAL, null, null)
            : Posting::credit(LedgerEntryType::Adjustment, $amount, $narration, ReferenceType::MANUAL, null, null);

        return DB::transaction(function () use ($organizationId, $partnerType, $partnerId, $posting, $reason): LedgerEntry {
            [$entry] = $this->postings->post($organizationId, $partnerType, $partnerId, [$posting]);
            $this->auditLogger->record('ledger.adjustment', $entry, [], ['reason' => $reason, 'debit' => (string) $entry->debit, 'credit' => (string) $entry->credit]);

            return $entry;
        });
    }
}
