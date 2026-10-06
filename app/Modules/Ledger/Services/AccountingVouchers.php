<?php

namespace App\Modules\Ledger\Services;

use App\Modules\Booking\Services\AccountingFacts;
use App\Modules\Booking\Services\DailyBilling;
use App\Modules\Booking\Services\DailyCollection;
use App\Modules\Ledger\Domain\AccountChart;
use App\Modules\Ledger\Domain\AccountingJournal;
use App\Modules\Ledger\Domain\BookAccount;
use App\Modules\Ledger\Domain\JournalVoucher;
use App\Modules\Ledger\Domain\MoneyDay;
use App\Modules\Ledger\Domain\PartnerMovement;
use App\Modules\Ledger\Domain\SalesDay;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Models\LedgerEntry;
use App\Modules\Network\Enums\PartnerType;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Network\Services\PartnerAccount;
use App\Modules\Network\Services\PartnerAccounts;
use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Collects a period's money and turns it into vouchers (spec §3 accounting
 * export). Runs at organization level inside the export job.
 */
final class AccountingVouchers
{
    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    private const PATIENTS_CODE = 'PAT';

    /** @var array<string, PartnerAccount> by partner type and ID, for one build */
    private array $partners = [];

    public function __construct(
        private readonly AccountingFacts $facts,
        private readonly NetworkDirectory $network,
        private readonly PartnerAccounts $partnerAccounts,
    ) {}

    /**
     * Company branches' sales, receipts and refunds, day by day.
     *
     * @return list<JournalVoucher>
     */
    public function sales(string $organizationId, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $chart = $this->chart();
        $branchCodes = $this->branchCodes($organizationId);
        $vouchers = [];

        for ($date = $periodStart; $date->lessThanOrEqualTo($periodEnd); $date = $date->addDay()) {
            foreach ($this->facts->billing($organizationId, $date) as $billing) {
                $vouchers[] = AccountingJournal::sales($chart, $this->salesDay($organizationId, $chart, $branchCodes, $billing));
            }

            foreach ($this->facts->collections($organizationId, $date) as $collection) {
                $vouchers[] = AccountingJournal::money($this->moneyDay($organizationId, $chart, $branchCodes, $collection));
            }
        }

        return array_values(array_filter($vouchers));
    }

    /**
     * One journal per franchise for the month's partner-ledger rows. B2B
     * client rows are left out: their invoices and payments are already in
     * the sales journal.
     *
     * @return list<JournalVoucher>
     */
    public function partnerLedger(string $organizationId, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $chart = $this->chart();
        $fromUtc = CarbonImmutable::parse($periodStart->toDateString(), self::BUSINESS_TIMEZONE)->utc();
        $untilUtc = CarbonImmutable::parse($periodEnd->toDateString(), self::BUSINESS_TIMEZONE)->addDay()->utc();

        $rows = LedgerEntry::query()
            ->where('organization_id', $organizationId)
            ->whereNotNull('franchise_id')
            ->where('created_at', '>=', $fromUtc)
            ->where('created_at', '<', $untilUtc)
            ->groupBy('franchise_id', 'entry_type')
            ->selectRaw('franchise_id, entry_type, sum(debit) as debit, sum(credit) as credit')
            ->toBase()
            ->get();

        $typeOrder = array_flip(array_map(fn (LedgerEntryType $type) => $type->value, LedgerEntryType::cases()));
        $vouchers = [];

        foreach ($rows->groupBy('franchise_id') as $franchiseId => $franchiseRows) {
            $movements = $franchiseRows
                ->sortBy(fn (object $row) => $typeOrder[$row->entry_type])
                ->map(fn (object $row) => new PartnerMovement(
                    LedgerEntryType::from((string) $row->entry_type),
                    Money::fromString((string) $row->debit),
                    Money::fromString((string) $row->credit),
                ))
                ->values()
                ->all();
            $franchise = $this->partner($organizationId, PartnerType::Franchise, (string) $franchiseId);

            $vouchers[] = AccountingJournal::partnerMonth($chart, $periodEnd, $franchise->code, $chart->franchise($franchise->code, $franchise->name), $movements);
        }

        $vouchers = array_values(array_filter($vouchers));
        usort($vouchers, fn (JournalVoucher $a, JournalVoucher $b) => $a->reference <=> $b->reference);

        return $vouchers;
    }

    /** @param  array<string, string>  $branchCodes */
    private function salesDay(string $organizationId, AccountChart $chart, array $branchCodes, DailyBilling $billing): SalesDay
    {
        $branchCode = $branchCodes[$billing->branchId];
        [$partyCode, $party] = $this->party($organizationId, $chart, $branchCode, $billing->b2bClientId);

        return new SalesDay($billing->date, $branchCode, $partyCode, $party, $billing->invoiceCount, $billing->amount, $billing->discount, $billing->tax, $billing->total);
    }

    /** @param  array<string, string>  $branchCodes */
    private function moneyDay(string $organizationId, AccountChart $chart, array $branchCodes, DailyCollection $collection): MoneyDay
    {
        $branchCode = $branchCodes[$collection->branchId];
        [$partyCode, $party] = $collection->forFranchise
            ? [self::PATIENTS_CODE, $chart->franchiseOnlineCollections()]
            : $this->party($organizationId, $chart, $branchCode, $collection->b2bClientId);

        return new MoneyDay(
            $collection->date,
            $branchCode,
            $partyCode,
            $party,
            $collection->mode->value,
            $chart->money($collection->mode->value, $branchCode),
            $collection->isRefund,
            $collection->count,
            $collection->amount,
        );
    }

    /** @return array{string, BookAccount} the party's code in voucher references, and its account */
    private function party(string $organizationId, AccountChart $chart, string $branchCode, ?string $b2bClientId): array
    {
        if ($b2bClientId === null) {
            return [self::PATIENTS_CODE, $chart->patients($branchCode)];
        }

        $client = $this->partner($organizationId, PartnerType::B2bClient, $b2bClientId);

        return [$client->code, $chart->b2bClient($client->code, $client->name)];
    }

    private function partner(string $organizationId, PartnerType $type, string $partnerId): PartnerAccount
    {
        return $this->partners["{$type->value}:{$partnerId}"] ??= $this->partnerAccounts->find($organizationId, $type, $partnerId)
            ?? throw new LogicException("Partner {$type->value} {$partnerId} not found.");
    }

    /** @return array<string, string> branch code by ID, closed branches included */
    private function branchCodes(string $organizationId): array
    {
        $codes = [];
        foreach ($this->network->branchPlacements($organizationId) as $branch) {
            $codes[$branch->branchId] = $branch->branchCode;
        }

        return $codes;
    }

    private function chart(): AccountChart
    {
        /** @var array{accounts: array<string, array{name: string, group: string}>, money_accounts: array<string, array{name: string, group: string}>, partner_entry_accounts: array<string, array{name: string, group: string}>} $config */
        $config = config('pathology.accounting');

        return new AccountChart($config);
    }
}
