<?php

namespace Tests\Feature\Shared;

use App\Modules\Network\Models\Organization;
use App\Modules\Shared\Numbering\FinancialYear;
use App\Modules\Shared\Numbering\NumberSequenceService;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Spec §11.4: parallel invoice numbering never duplicates. Real parallelism:
 * forked processes, each with its own database connection, take numbers from
 * the same series at the same time. Commits its own rows, so it does not use
 * RefreshDatabase, and cleans up afterwards.
 */
final class InvoiceNumberingConcurrencyTest extends TestCase
{
    private const WORKERS = 4;

    private const NUMBERS_PER_WORKER = 25;

    private ?string $organizationId = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Needs the pcntl extension (CLI).');
        }

        if (! Schema::hasTable('number_sequences')) {
            Artisan::call('migrate', ['--force' => true]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->organizationId !== null) {
            DB::table('number_sequences')->where('organization_id', $this->organizationId)->delete();
            DB::table('organizations')->where('id', $this->organizationId)->delete();
        }

        parent::tearDown();
    }

    public function test_parallel_workers_never_get_the_same_invoice_number(): void
    {
        $this->organizationId = app(CurrentScope::class)->runAs(ScopeContext::system(), fn () => Organization::factory()->create()->id);
        $outputDirectory = sys_get_temp_dir().'/numbering-'.bin2hex(random_bytes(6));
        mkdir($outputDirectory);

        // Children must not share the parent's socket.
        DB::disconnect();

        $children = [];
        for ($worker = 0; $worker < self::WORKERS; $worker++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                $this->takeNumbers("{$outputDirectory}/{$worker}.json");
            }

            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        DB::reconnect();
        $numbers = [];
        foreach (glob("{$outputDirectory}/*.json") ?: [] as $file) {
            $numbers = [...$numbers, ...json_decode((string) file_get_contents($file), true)];
            unlink($file);
        }
        rmdir($outputDirectory);

        sort($numbers);
        $this->assertCount(self::WORKERS * self::NUMBERS_PER_WORKER, $numbers, 'Every worker should have finished.');
        $this->assertSame(range(1, self::WORKERS * self::NUMBERS_PER_WORKER), $numbers, 'Numbers must be unique and gap-free.');
    }

    /** Runs in a child process; never returns. */
    private function takeNumbers(string $outputFile): never
    {
        DB::reconnect();
        $sequences = app(NumberSequenceService::class);
        $year = FinancialYear::containing(CarbonImmutable::now());
        $taken = [];

        for ($i = 0; $i < self::NUMBERS_PER_WORKER; $i++) {
            $taken[] = DB::transaction(fn () => $sequences->next((string) $this->organizationId, 'invoice:race', $year));
        }

        file_put_contents($outputFile, json_encode($taken));

        // Replace the child process without running PHPUnit's shutdown handlers.
        pcntl_exec('/bin/true');
        exit(0);
    }
}
