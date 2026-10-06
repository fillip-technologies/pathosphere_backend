<?php

namespace App\Modules\Ledger\Services;

use App\Modules\Ledger\Domain\Posting;
use App\Modules\Ledger\Domain\WalletSufficiency;
use App\Modules\Ledger\Errors\LedgerError;
use App\Modules\Ledger\Models\LedgerEntry;
use App\Modules\Network\Enums\PartnerType;
use App\Modules\Network\Services\PartnerAccounts;
use App\Modules\Shared\Context\CurrentActor;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of partner_ledger (spec §7.8 posting rule): lock the
 * partner row, read its balance, insert each row with `balance_after`,
 * store the new balance, all in one transaction. Parallel postings for the
 * same partner queue on the row lock, so balances never race.
 *
 * Postings that carry an idempotency key are written once: posting the same
 * key again returns the existing row and moves no money.
 */
final class LedgerPostingService
{
    public function __construct(
        private readonly PartnerAccounts $accounts,
        private readonly CurrentActor $currentActor,
        private readonly CurrentScope $currentScope,
    ) {}

    /**
     * @param  list<Posting>  $postings
     * @param  bool  $enforceWallet  refuse with WALLET_INSUFFICIENT if the new debits exceed balance + credit limit
     * @return list<LedgerEntry> the rows, new or already there, in posting order
     */
    public function post(string $organizationId, PartnerType $partnerType, string $partnerId, array $postings, bool $enforceWallet = false): array
    {
        if ($postings === []) {
            return [];
        }

        return DB::transaction(function () use ($organizationId, $partnerType, $partnerId, $postings, $enforceWallet): array {
            $account = $this->accounts->lockForPosting($organizationId, $partnerType, $partnerId);

            // Postings are bookkeeping for an action already authorised; the
            // rows belong to the partner, whom the caller may not see.
            return $this->currentScope->runAs(ScopeContext::system($organizationId), function () use ($account, $postings, $enforceWallet): array {
                $existing = $this->existingByKey($postings);
                $fresh = array_values(array_filter($postings, fn (Posting $posting) => $posting->idempotencyKey === null || ! isset($existing[$posting->idempotencyKey])));

                if ($enforceWallet) {
                    $this->assertWalletCovers($account->balance, $account->creditLimit, $fresh);
                }

                $balance = $account->balance;
                $rows = [];
                $postedAt = $this->nextPostingTime($account->type, $account->id);

                foreach ($postings as $posting) {
                    if ($posting->idempotencyKey !== null && isset($existing[$posting->idempotencyKey])) {
                        $rows[] = $existing[$posting->idempotencyKey];

                        continue;
                    }

                    $balance = $balance->add($posting->effect());
                    $rows[] = $this->insert($account->organizationId, $account->type, $account->id, $posting, $balance, $postedAt);
                    $postedAt = $postedAt->addMicrosecond();
                }

                if (! $balance->equals($account->balance)) {
                    $this->accounts->recordBalance($account->organizationId, $account->type, $account->id, $balance);
                }

                return $rows;
            });
        });
    }

    /**
     * Each row of a partner gets a later time than the one before, even when
     * the clock has not moved on, so ordering by time is the posting order
     * that `balance_after` follows. Safe because the partner row is locked.
     */
    private function nextPostingTime(PartnerType $partnerType, string $partnerId): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $last = LedgerEntry::query()
            ->where($partnerType === PartnerType::Franchise ? 'franchise_id' : 'b2b_client_id', $partnerId)
            ->max('created_at');

        if ($last === null) {
            return $now;
        }

        $afterLast = CarbonImmutable::parse((string) $last, 'UTC')->addMicrosecond();

        return $afterLast->isAfter($now) ? $afterLast : $now;
    }

    /**
     * @param  list<Posting>  $postings
     * @return array<string, LedgerEntry>
     */
    private function existingByKey(array $postings): array
    {
        $keys = array_values(array_filter(array_map(fn (Posting $posting) => $posting->idempotencyKey, $postings)));

        if ($keys === []) {
            return [];
        }

        return LedgerEntry::query()->whereIn('idempotency_key', $keys)->get()->keyBy('idempotency_key')->all();
    }

    /** @param  list<Posting>  $postings */
    private function assertWalletCovers(Money $balance, Money $creditLimit, array $postings): void
    {
        $required = array_reduce($postings, fn (Money $sum, Posting $posting) => $sum->subtract($posting->effect()), Money::zero());

        if ($required->isGreaterThan(Money::zero()) && ! WalletSufficiency::covers($balance, $creditLimit, $required)) {
            throw LedgerError::walletInsufficient(WalletSufficiency::available($balance, $creditLimit), $required);
        }
    }

    private function insert(string $organizationId, PartnerType $partnerType, string $partnerId, Posting $posting, Money $balanceAfter, CarbonImmutable $postedAt): LedgerEntry
    {
        $entry = new LedgerEntry;
        $entry->forceFill([
            'created_at' => $postedAt,
            'updated_at' => $postedAt,
            'organization_id' => $organizationId,
            'franchise_id' => $partnerType === PartnerType::Franchise ? $partnerId : null,
            'b2b_client_id' => $partnerType === PartnerType::B2bClient ? $partnerId : null,
            'entry_type' => $posting->entryType,
            'reference_type' => $posting->referenceType,
            'reference_id' => $posting->referenceId,
            'debit' => $posting->debit,
            'credit' => $posting->credit,
            'balance_after' => $balanceAfter,
            'narration' => mb_substr($posting->narration, 0, 255),
            'idempotency_key' => $posting->idempotencyKey,
            'created_by' => $this->currentActor->userId(),
        ])->save();

        return $entry;
    }
}
