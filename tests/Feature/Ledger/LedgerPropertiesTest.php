<?php

namespace Tests\Feature\Ledger;

use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Ledger\Domain\Posting;
use App\Modules\Ledger\Domain\ReferenceType;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Jobs\BuildSettlements;
use App\Modules\Ledger\Models\LedgerEntry;
use App\Modules\Ledger\Models\Settlement;
use App\Modules\Ledger\Models\SettlementItem;
use App\Modules\Ledger\Services\LedgerPostingService;
use App\Modules\Network\Enums\AgreementStatus;
use App\Modules\Network\Enums\BillingModel;
use App\Modules\Network\Enums\FranchiseModel;
use App\Modules\Network\Enums\PartnerType;
use App\Modules\Network\Enums\SettlementCycle;
use App\Modules\Network\Models\Franchise;
use App\Modules\Network\Models\FranchiseAgreement;
use App\Modules\Network\Models\Region;
use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Auth\BuildsStaff;
use Tests\Support\Ledger\MovesMoney;
use Tests\TestCase;

/**
 * Spec §11.3 money properties, over random months of postings (fixed seeds,
 * so a failure reproduces): the ledger rows always add up to the cached
 * balance, every row is settled exactly once, and settling a statement
 * brings the account to what the statement promised.
 */
final class LedgerPropertiesTest extends TestCase
{
    use BuildsStaff;
    use MovesMoney;
    use RefreshDatabase;

    private const SEEDS = [20261006, 7, 424242];

    private const POSTINGS_PER_MONTH = 25;

    private const MONTHS = ['2026-07', '2026-08', '2026-09'];

    public function test_random_months_of_postings_add_up_and_settle_each_row_exactly_once(): void
    {
        Storage::fake('local');
        $this->setUpOrganization();
        $finance = $this->staff(SystemRole::HqFinance);
        $postings = app(LedgerPostingService::class);

        foreach (self::SEEDS as $seed) {
            mt_srand($seed);
            $franchise = $this->wholesaleFranchise("FRP{$seed}");

            foreach (self::MONTHS as $month) {
                foreach ($this->randomTimesIn($month) as $postedAt) {
                    $this->travelTo($postedAt);
                    $this->asSystem(fn () => $postings->post($this->organization->id, PartnerType::Franchise, $franchise->id, [$this->randomPosting()]));
                    $this->assertLedgerAddsUp('franchise_id', $franchise->id, $this->balanceOf($franchise));
                }

                // The nightly job after month end, then finance approves and settles it.
                $balanceAtMonthEnd = Money::fromString($this->balanceOf($franchise));
                $this->travelTo(CarbonImmutable::parse("{$month}-01", 'Asia/Kolkata')->addMonth()->setTime(2, 0));
                dispatch_sync(new BuildSettlements);
                dispatch_sync(new BuildSettlements);

                $settlement = $this->asSystem(fn () => Settlement::query()->where('franchise_id', $franchise->id)->where('period_end', CarbonImmutable::parse("{$month}-01")->endOfMonth()->toDateString())->sole());
                $this->assertSame((string) $balanceAtMonthEnd, (string) $settlement->closing_balance, "seed {$seed}, {$month}");
                $this->assertSame($this->expectedNet($balanceAtMonthEnd), (string) $settlement->net_amount, "seed {$seed}, {$month}");

                $this->actingAsStaff($finance)->postJson("/api/v1/settlements/{$settlement->id}/approve")->assertOk();
                $this->actingAsStaff($finance)->postJson("/api/v1/settlements/{$settlement->id}/mark-settled", ['payment_reference' => "UTR{$seed}{$month}"])->assertOk();

                // Settled: a postpaid balance goes to zero; a prepaid credit stays in the wallet.
                $expectedAfter = $settlement->closing_balance->isNegative() ? '0.00' : (string) $settlement->closing_balance;
                $this->assertSame($expectedAfter, $this->balanceOf($franchise), "seed {$seed}, {$month}");
                $this->assertLedgerAddsUp('franchise_id', $franchise->id, $expectedAfter);
            }

            // Every row is in exactly one settlement.
            $rowIds = $this->asSystem(fn () => LedgerEntry::query()->where('franchise_id', $franchise->id)->pluck('id')->sort()->values()->all());
            $settledIds = SettlementItem::query()->whereIn('ledger_entry_id', $rowIds)->pluck('ledger_entry_id')->sort()->values()->all();
            $this->assertSame($rowIds, $settledIds);
        }

        // And the database refuses a second settlement for a settled row.
        $item = SettlementItem::query()->firstOrFail();
        $this->expectException(QueryException::class);
        (new SettlementItem)->forceFill(['settlement_id' => $item->settlement_id, 'ledger_entry_id' => $item->ledger_entry_id])->save();
    }

    private function wholesaleFranchise(string $code): Franchise
    {
        return $this->asSystem(function () use ($code): Franchise {
            $region = Region::factory()->create(['organization_id' => $this->organization->id]);
            $franchise = Franchise::factory()->in($region)->create(['franchise_code' => $code, 'credit_limit' => '100000.00']);

            $agreement = new FranchiseAgreement([
                'franchise_model' => FranchiseModel::Psc,
                'billing_model' => BillingModel::Wholesale,
                'settlement_cycle' => SettlementCycle::Monthly,
                'start_date' => '2026-07-01',
                'end_date' => '2029-06-30',
                'status' => AgreementStatus::Active,
            ]);
            $agreement->organization_id = $this->organization->id;
            $agreement->franchise_id = $franchise->id;
            $agreement->agreement_no = "AGR-{$code}";
            $agreement->save();

            return $franchise;
        });
    }

    /** @return list<CarbonImmutable> increasing times inside the month, in business time */
    private function randomTimesIn(string $month): array
    {
        $start = CarbonImmutable::parse("{$month}-01 00:00", 'Asia/Kolkata');
        $seconds = $start->endOfMonth()->getTimestamp() - $start->getTimestamp();
        $offsets = array_map(fn () => mt_rand(0, $seconds), range(1, self::POSTINGS_PER_MONTH));
        sort($offsets);

        return array_map(fn (int $offset) => $start->addSeconds($offset), $offsets);
    }

    private function randomPosting(): Posting
    {
        $amount = Money::fromPaise(mt_rand(1, 500000));

        return match (mt_rand(0, 3)) {
            0 => Posting::credit(LedgerEntryType::WalletTopup, $amount, 'Top-up', ReferenceType::MANUAL, null, null),
            1 => Posting::credit(LedgerEntryType::Adjustment, $amount, 'Credit adjustment', ReferenceType::MANUAL, null, null),
            2 => Posting::debit(LedgerEntryType::Adjustment, $amount, 'Debit adjustment', ReferenceType::MANUAL, null, null),
            default => Posting::debit(LedgerEntryType::PartnerCharge, $amount, 'Test charge', ReferenceType::ORDER_ITEM, null, null),
        };
    }

    /** What a wholesale franchise owes: the negative part of its balance at month end; a credit is kept. */
    private function expectedNet(Money $balanceAtMonthEnd): string
    {
        return $balanceAtMonthEnd->isNegative() ? (string) Money::zero()->subtract($balanceAtMonthEnd) : '0.00';
    }

    private function balanceOf(Franchise $franchise): string
    {
        return $this->asSystem(fn () => (string) Franchise::query()->findOrFail($franchise->id)->current_balance);
    }
}
