<?php

namespace Tests\Feature\Ledger;

use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Booking\Models\Invoice;
use App\Modules\Ledger\Domain\Posting;
use App\Modules\Ledger\Domain\ReferenceType;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Jobs\AlertLowWalletBalances;
use App\Modules\Ledger\Jobs\HoldOverduePartners;
use App\Modules\Ledger\Jobs\RemindB2bDues;
use App\Modules\Ledger\Services\LedgerPostingService;
use App\Modules\Network\Enums\AgreementStatus;
use App\Modules\Network\Enums\PartnerType;
use App\Modules\Network\Jobs\AlertExpiringNetworkPapers;
use App\Modules\Network\Jobs\ExpireEndedAgreements;
use App\Modules\Network\Models\FranchiseAgreement;
use App\Modules\Samples\Jobs\AlertExpiringStock;
use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Auth\BuildsStaff;
use Tests\Support\Booking\BooksOrders;
use Tests\Support\Ledger\MovesMoney;
use Tests\TestCase;

/** Scheduled partner jobs (spec §9): expiry, expiry alerts, low wallet, auto-hold and dues reminders. */
final class PartnerJobsTest extends TestCase
{
    use BooksOrders;
    use BuildsStaff;
    use MovesMoney;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-06 11:00', 'Asia/Kolkata'));
        $this->setUpDemoNetwork();
    }

    public function test_agreements_expire_after_their_end_date_and_are_announced_before_it(): void
    {
        $this->setAgreementEnd('FRGAYA', '2026-10-05');
        $this->setAgreementEnd('FRDHN', '2026-11-05');

        dispatch_sync(new ExpireEndedAgreements);
        dispatch_sync(new AlertExpiringNetworkPapers);

        $this->assertSame(AgreementStatus::Expired, $this->asSystem(fn () => FranchiseAgreement::query()->where('franchise_id', $this->franchiseId('FRGAYA'))->value('status')));
        $this->assertSame(AgreementStatus::Active, $this->asSystem(fn () => FranchiseAgreement::query()->where('franchise_id', $this->franchiseId('FRDHN'))->value('status')));
        $this->assertSame(
            ['Dhanbad Health Point: your agreement AGR-DEMO-FRDHN expires on 05 Nov 2026 (30 days). Please renew it.'],
            array_column($this->messages->sent, 'body'),
        );
    }

    public function test_a_wholesale_franchise_low_on_money_is_told_once_a_day(): void
    {
        dispatch_sync(new AlertLowWalletBalances);
        dispatch_sync(new AlertLowWalletBalances);

        // Gaya's wallet is empty; Dhanbad shares revenue and has no wallet to watch.
        $this->assertCount(1, $this->messages->sent);
        $this->assertStringContainsString('Gaya Diagnostics: wallet balance Rs 0.00', $this->messages->sent[0]['body']);
    }

    public function test_auto_hold_suspends_a_partner_past_its_limit_for_longer_than_the_grace_period(): void
    {
        // Gaya has no credit limit and goes Rs 100 into debt today.
        $this->asSystem(fn () => app(LedgerPostingService::class)->post($this->organization->id, PartnerType::Franchise, $this->franchiseId('FRGAYA'), [
            Posting::debit(LedgerEntryType::Adjustment, Money::fromString('100'), 'Owed', ReferenceType::MANUAL, null, null),
        ]));
        config(['pathology.ledger.auto_hold.enabled' => true]);

        // Within the 15 grace days nothing happens.
        $this->travelTo(CarbonImmutable::parse('2026-10-20 11:00', 'Asia/Kolkata'));
        dispatch_sync(new HoldOverduePartners);
        $withinGrace = $this->franchiseStatus('FRGAYA');

        // Past the grace days, but auto-hold is switched off (the default, spec §12).
        $this->travelTo(CarbonImmutable::parse('2026-10-25 11:00', 'Asia/Kolkata'));
        config(['pathology.ledger.auto_hold.enabled' => false]);
        dispatch_sync(new HoldOverduePartners);
        $switchedOff = $this->franchiseStatus('FRGAYA');

        config(['pathology.ledger.auto_hold.enabled' => true]);
        dispatch_sync(new HoldOverduePartners);

        $this->assertSame(['active', 'active', 'suspended', 'active'], [$withinGrace, $switchedOff, $this->franchiseStatus('FRGAYA'), $this->franchiseStatus('FRDHN')]);
    }

    public function test_b2b_clients_with_overdue_invoices_get_a_reminder(): void
    {
        $labDesk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $this->branchId('PATCL1')]);
        $patientId = $this->actingAsStaff($labDesk)->registerPatient()['id'];
        $this->bookOrder($patientId, 'PATCL1', [
            'order_source' => 'b2b', 'b2b_client_id' => $this->clientId('CLPCH'), 'items' => [$this->testItem('CBC')],
        ])->assertCreated();
        $this->messages->clear();

        dispatch_sync(new RemindB2bDues);
        $this->assertCount(0, $this->messages->sent);

        $this->asSystem(fn () => Invoice::query()->where('b2b_client_id', $this->clientId('CLPCH'))->update(['due_date' => '2026-10-01']));
        dispatch_sync(new RemindB2bDues);
        $this->assertStringContainsString('Patna City Hospital: Rs 245.00 is overdue on 1 invoice(s), the oldest due 01 Oct 2026', $this->messages->sent[0]['body']);
    }

    public function test_stock_nearing_expiry_is_announced_to_its_branch(): void
    {
        $admin = $this->staff(SystemRole::BranchAdmin, ['branch_id' => $this->branchId('PATCL1')]);
        $this->actingAsStaff($admin)->postJson('/api/v1/inventory-items', [
            'branch_id' => $this->branchId('PATCL1'), 'item_code' => 'CBC-REAG', 'name' => 'CBC reagent pack', 'category' => 'reagent',
            'unit' => 'pack', 'quantity' => '3', 'batch_no' => 'R9', 'expiry_date' => '2026-12-05',
        ])->assertCreated();

        dispatch_sync(new AlertExpiringStock);

        $this->assertSame(['Patna Clinical Lab: CBC reagent pack batch R9 expires on 05 Dec 2026.'], array_column($this->messages->sent, 'body'));
    }

    private function setAgreementEnd(string $franchiseCode, string $endDate): void
    {
        $this->asSystem(fn () => FranchiseAgreement::query()->where('franchise_id', $this->franchiseId($franchiseCode))->update(['end_date' => $endDate]));
    }

    private function franchiseStatus(string $code): string
    {
        return $this->actingAsStaff($this->staff(SystemRole::SuperAdmin))->getJson('/api/v1/franchises/'.$this->franchiseId($code))->json('data.status');
    }
}
