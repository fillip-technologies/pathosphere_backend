<?php

namespace App\Modules\Ledger\Jobs;

use App\Modules\Ledger\Services\AccountingExports;
use App\Modules\Shared\Jobs\WithSystemScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use LogicException;
use Throwable;

/** Builds one accounting export file. Safe to retry; marks the export failed when it gives up. */
final class GenerateAccountingExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly string $exportId,
        public readonly string $organizationId,
    ) {
        $this->onQueue('low');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new WithSystemScope($this->organizationId)];
    }

    public function handle(AccountingExports $exports): void
    {
        $exports->generate($this->exportId);
    }

    public function failed(Throwable $exception): void
    {
        // Configuration problems (a missing account name) are for HQ to fix; anything else is in the logs.
        $reason = $exception instanceof LogicException
            ? $exception->getMessage()
            : 'The export could not be built. Ask for a new one; if it fails again, check the application logs.';

        WithSystemScope::run($this->organizationId, fn () => app(AccountingExports::class)->markFailed($this->exportId, $reason));
    }
}
