<?php

namespace Tests\Feature\Ledger;

use App\Modules\Ledger\Domain\Posting;
use App\Modules\Ledger\Domain\ReferenceType;
use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Ledger\Services\LedgerPostingService;
use App\Modules\Network\Enums\PartnerType;
use App\Modules\Network\Models\Franchise;
use App\Modules\Network\Models\Organization;
use App\Modules\Network\Models\Region;
use App\Modules\Shared\Errors\DomainError;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Spec §11.4: parallel wallet debits never overdraw beyond the credit limit.
 * Forked processes, each with its own connection, debit the same wallet at
 * the same time; the partner row lock must serialise them. Commits its own
 * rows, so it does not use RefreshDatabase, and cleans up afterwards.
 */
final class WalletConcurrencyTest extends TestCase
{
    private const WORKERS = 4;

    private const DEBITS_PER_WORKER = 10;

    private const CREDIT_LIMIT = '1000.00';

    private const DEBIT = '50.00';

    private ?string $organizationId = null;

    private ?string $franchiseId = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Needs the pcntl extension (CLI).');
        }

        if (! Schema::hasTable('partner_ledger')) {
            Artisan::call('migrate', ['--force' => true]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->organizationId !== null) {
            DB::table('partner_ledger')->where('organization_id', $this->organizationId)->delete();
            DB::table('franchises')->where('organization_id', $this->organizationId)->delete();
            DB::table('regions')->where('organization_id', $this->organizationId)->delete();
            DB::table('organizations')->where('id', $this->organizationId)->delete();
        }

        parent::tearDown();
    }

    public function test_parallel_debits_never_take_a_wallet_past_its_credit_limit(): void
    {
        app(CurrentScope::class)->runAs(ScopeContext::system(), function (): void {
            $organization = Organization::factory()->create();
            $region = Region::factory()->create(['organization_id' => $organization->id]);
            $franchise = Franchise::factory()->in($region)->create(['credit_limit' => self::CREDIT_LIMIT]);
            $this->organizationId = $organization->id;
            $this->franchiseId = $franchise->id;
        });

        $outputDirectory = sys_get_temp_dir().'/wallet-'.bin2hex(random_bytes(6));
        mkdir($outputDirectory);
        DB::disconnect();

        $children = [];
        for ($worker = 0; $worker < self::WORKERS; $worker++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                $this->debit("{$outputDirectory}/{$worker}.json", $worker);
            }

            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        DB::reconnect();
        $accepted = 0;
        $refused = 0;
        foreach (glob("{$outputDirectory}/*.json") ?: [] as $file) {
            $result = json_decode((string) file_get_contents($file), true);
            $accepted += $result['accepted'];
            $refused += $result['refused'];
            unlink($file);
        }
        rmdir($outputDirectory);

        // 40 debits of 50 against a limit of 1000: exactly 20 fit.
        $this->assertSame(self::WORKERS * self::DEBITS_PER_WORKER, $accepted + $refused, 'Every worker should have finished.');
        $this->assertSame(20, $accepted);

        $balance = app(CurrentScope::class)->runAs(ScopeContext::system(), fn () => (string) Franchise::query()->findOrFail($this->franchiseId)->current_balance);
        $this->assertSame('-1000.00', $balance);

        $rows = DB::table('partner_ledger')->where('franchise_id', $this->franchiseId)->orderBy('created_at')->orderBy('id')->get();
        $running = Money::zero();
        foreach ($rows as $row) {
            $running = $running->subtract(Money::fromString((string) $row->debit));
            $this->assertSame((string) $running, Money::fromString((string) $row->balance_after)->toDecimalString());
            $this->assertFalse($running->isLessThan(Money::fromString('-'.self::CREDIT_LIMIT)));
        }
    }

    /** Runs in a child process; never returns. An unexpected error leaves no result file, which fails the test. */
    private function debit(string $outputFile, int $worker): never
    {
        try {
            DB::reconnect();
            $postings = app(LedgerPostingService::class);
            $result = ['accepted' => 0, 'refused' => 0];

            for ($i = 0; $i < self::DEBITS_PER_WORKER; $i++) {
                try {
                    app(CurrentScope::class)->runAs(ScopeContext::system(), fn () => $postings->post(
                        (string) $this->organizationId,
                        PartnerType::Franchise,
                        (string) $this->franchiseId,
                        [Posting::debit(LedgerEntryType::PartnerCharge, Money::fromString(self::DEBIT), 'Race', ReferenceType::ORDER_ITEM, null, "race:{$worker}:{$i}")],
                        enforceWallet: true,
                    ));
                    $result['accepted']++;
                } catch (DomainError $error) {
                    if ($error->errorCode !== 'WALLET_INSUFFICIENT') {
                        throw $error;
                    }

                    $result['refused']++;
                }
            }

            file_put_contents($outputFile, json_encode($result));
        } finally {
            // Replace the child process without running PHPUnit's shutdown handlers.
            pcntl_exec('/bin/true');
            exit(0);
        }
    }
}
