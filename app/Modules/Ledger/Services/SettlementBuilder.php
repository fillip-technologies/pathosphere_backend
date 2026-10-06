<?php

namespace App\Modules\Ledger\Services;

use App\Modules\Booking\Services\PartnerBillingFacts;
use App\Modules\Ledger\Domain\LedgerLine;
use App\Modules\Ledger\Domain\PartnerModel;
use App\Modules\Ledger\Domain\PostingRules;
use App\Modules\Ledger\Domain\SettlementCalculator;
use App\Modules\Ledger\Domain\SettlementPeriod;
use App\Modules\Ledger\Enums\SettlementStatus;
use App\Modules\Ledger\Jobs\RenderSettlementStatement;
use App\Modules\Ledger\Models\LedgerEntry;
use App\Modules\Ledger\Models\Settlement;
use App\Modules\Ledger\Models\SettlementItem;
use App\Modules\Ledger\StateMachines\SettlementStateMachine;
use App\Modules\Network\Enums\PartnerType;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Network\Services\PartnerAccount;
use App\Modules\Network\Services\PartnerAccounts;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Numbering\FinancialYear;
use App\Modules\Shared\Numbering\NumberSequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds settlements at cycle ends (spec §5.6 step 3, §9 settlement builder).
 * Run as the system of one organization.
 *
 * A settlement covers every ledger row of the partner not yet settled up to
 * the period end, plus, for a revenue-share franchise, the cash and
 * commission rows posted at close. The amount to settle is the account
 * balance at the period end (SettlementCalculator). One open settlement per
 * partner at a time: while one awaits approval or payment, later cycles wait
 * and are covered together by the next one.
 */
final class SettlementBuilder
{
    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    private const DEFAULT_NUMBER_FORMAT = 'STL/{FY}/{seq:5}';

    public function __construct(
        private readonly PartnerAccounts $accounts,
        private readonly PartnerBillingFacts $billing,
        private readonly LedgerPostingService $postings,
        private readonly NumberSequenceService $sequences,
        private readonly SettlementStateMachine $stateMachine,
        private readonly NetworkDirectory $network,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** @return list<Settlement> the settlements created */
    public function buildDue(string $organizationId, CarbonImmutable $today): array
    {
        $built = [];

        foreach ($this->accounts->settlingPartners($organizationId) as $account) {
            $period = SettlementPeriod::lastClosedBefore($account->settlementCycle(), $today);
            $previous = $this->partnerSettlements($account)->orderByDesc('period_end')->first();

            if ($previous !== null && ($previous->status !== SettlementStatus::Settled || ! $previous->period_end->isBefore($period->end))) {
                continue;
            }

            $start = $previous?->period_end->addDay() ?? $this->firstPeriodStart($account, $period);
            $settlement = $this->build($account, $start, $period->end);

            if ($settlement !== null) {
                $built[] = $settlement;
            }
        }

        return $built;
    }

    public function build(PartnerAccount $account, CarbonImmutable $start, CarbonImmutable $end): ?Settlement
    {
        $model = PartnerModels::of($account);

        if ($model === null) {
            return null;
        }

        $untilUtc = $this->endOfBusinessDay($end);
        $patientBilling = $account->isFranchise()
            ? $this->billing->franchisePeriod($account->organizationId, $account->id, $start, $end)
            : null;

        return DB::transaction(function () use ($account, $model, $start, $end, $untilUtc, $patientBilling): ?Settlement {
            // Holds postings for this partner until the settlement is saved.
            $this->accounts->lockForPosting($account->organizationId, $account->type, $account->id);

            $closingRows = $model === PartnerModel::RevenueShare && $patientBilling !== null
                ? $this->postings->post($account->organizationId, $account->type, $account->id, PostingRules::revenueShareClosed(
                    $account->id,
                    $start->toDateString().'..'.$end->toDateString(),
                    $patientBilling->cashCollected,
                    $patientBilling->netBilled,
                    (string) $account->terms?->commissionPct,
                ))
                : [];

            $rows = $this->unsettledRows($account, $untilUtc)->merge($closingRows)->unique('id')->values();
            $grossBilling = $patientBilling->netBilled ?? Money::zero();

            if ($rows->isEmpty() && $grossBilling->isZero()) {
                return null;
            }

            $closingBalance = array_reduce(
                $closingRows,
                fn (Money $balance, LedgerEntry $row) => $balance->add($row->credit)->subtract($row->debit),
                $this->balanceBefore($account, $untilUtc),
            );

            $figures = SettlementCalculator::calculate(
                $model,
                $grossBilling,
                $rows->map(fn (LedgerEntry $row) => new LedgerLine($row->entry_type, $row->debit, $row->credit))->all(),
                $closingBalance,
            );

            $settlement = new Settlement([
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'gross_billing' => $figures->grossBilling,
                'partner_share' => $figures->partnerShare,
                'hq_share' => $figures->hqShare,
                'tax' => $figures->tax,
                'net_amount' => $figures->netAmount,
                'closing_balance' => $closingBalance,
                'direction' => $figures->direction,
                'status' => SettlementStatus::Draft,
            ]);
            $settlement->organization_id = $account->organizationId;
            $settlement->franchise_id = $account->type === PartnerType::Franchise ? $account->id : null;
            $settlement->b2b_client_id = $account->type === PartnerType::B2bClient ? $account->id : null;
            $settlement->settlement_no = $this->settlementNumber($account->organizationId, $end);
            $settlement->save();
            $this->auditLogger->recordCreated('settlement.create', $settlement);

            foreach ($rows as $row) {
                $item = new SettlementItem;
                $item->forceFill(['settlement_id' => $settlement->id, 'ledger_entry_id' => $row->id])->save();
            }

            $this->stateMachine->transition($settlement, SettlementStatus::PendingApproval);
            RenderSettlementStatement::dispatch($settlement->id, $settlement->organization_id)->afterCommit();

            return $settlement;
        });
    }

    /** @return Builder<Settlement> */
    private function partnerSettlements(PartnerAccount $account): Builder
    {
        return Settlement::query()->where($account->type === PartnerType::Franchise ? 'franchise_id' : 'b2b_client_id', $account->id);
    }

    /** @return Builder<LedgerEntry> */
    private function partnerRows(PartnerAccount $account): Builder
    {
        return LedgerEntry::query()->where($account->type === PartnerType::Franchise ? 'franchise_id' : 'b2b_client_id', $account->id);
    }

    /** @return Collection<int, LedgerEntry> */
    private function unsettledRows(PartnerAccount $account, CarbonImmutable $untilUtc): Collection
    {
        return $this->partnerRows($account)
            ->where('created_at', '<', $untilUtc)
            ->whereNotIn('id', SettlementItem::query()->select('ledger_entry_id'))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /** Sum of every row before the cut-off: the balance at that moment, whatever order rows were written in. */
    private function balanceBefore(PartnerAccount $account, CarbonImmutable $untilUtc): Money
    {
        $totals = $this->partnerRows($account)
            ->where('created_at', '<', $untilUtc)
            ->toBase()
            ->selectRaw('coalesce(sum(credit), 0) as credits, coalesce(sum(debit), 0) as debits')
            ->first();

        return Money::fromString((string) ($totals->credits ?? '0'))->subtract(Money::fromString((string) ($totals->debits ?? '0')));
    }

    /**
     * The first settlement starts at the earlier of the cycle start and the
     * partner's oldest unsettled row (e.g. a fee posted mid-cycle at signing).
     */
    private function firstPeriodStart(PartnerAccount $account, SettlementPeriod $period): CarbonImmutable
    {
        $oldest = $this->partnerRows($account)->min('created_at');

        if ($oldest === null) {
            return $period->start;
        }

        $oldestDate = CarbonImmutable::parse(CarbonImmutable::parse((string) $oldest, 'UTC')->setTimezone(self::BUSINESS_TIMEZONE)->toDateString());

        return $oldestDate->isBefore($period->start) ? $oldestDate : $period->start;
    }

    private function endOfBusinessDay(CarbonImmutable $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString(), self::BUSINESS_TIMEZONE)->addDay()->utc();
    }

    private function settlementNumber(string $organizationId, CarbonImmutable $periodEnd): string
    {
        $format = (string) ($this->network->organizationSettings($organizationId)['settlement_number_format'] ?? self::DEFAULT_NUMBER_FORMAT);

        return $this->sequences->nextFormatted($organizationId, 'settlement', $format, [], FinancialYear::containing($periodEnd));
    }
}
