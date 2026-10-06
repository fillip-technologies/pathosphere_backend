<?php

namespace App\Modules\Locker\Console;

use App\Modules\Lab\Services\ReleasedReports;
use App\Modules\Locker\Services\ReleasedReportBridge;
use App\Modules\Shared\Jobs\WithSystemScope;
use Illuminate\Console\Command;

/**
 * Puts reports released before the health locker existed into it. Safe to
 * run again: copied reports are skipped.
 */
final class BackfillLocker extends Command
{
    protected $signature = 'locker:backfill';

    protected $description = 'Copy every released report into its patient\'s health locker';

    public function handle(ReleasedReports $reports, ReleasedReportBridge $bridge): int
    {
        $copied = 0;

        $reports->eachPublished(function (string $reportId, string $organizationId) use ($bridge, &$copied): void {
            WithSystemScope::run($organizationId, fn () => $bridge->copy($organizationId, $reportId));
            $copied++;
        });

        $this->info("Checked {$copied} released report(s); missing ones are now in the locker.");

        return self::SUCCESS;
    }
}
