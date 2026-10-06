<?php

namespace App\Modules\Lab\Services;

use App\Modules\Lab\Models\Report;
use App\Modules\Lab\Models\WorklistEntry;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Carbon\CarbonImmutable;

/** Lab totals per processing lab for one business day, for the nightly dashboard summary. */
final class LabDailyFigures
{
    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    public function __construct(private readonly CurrentScope $currentScope) {}

    /** @return array<string, int> reports released that day, by processing lab */
    public function reportsReleased(string $organizationId, CarbonImmutable $date): array
    {
        [$fromUtc, $untilUtc] = $this->bounds($date);

        return $this->currentScope->runAs(ScopeContext::system($organizationId), fn (): array => Report::query()
            ->where('released_at', '>=', $fromUtc)
            ->where('released_at', '<', $untilUtc)
            ->get(['processing_branch_id'])
            ->countBy('processing_branch_id')
            ->all());
    }

    /** @return array<string, int> tests that breached their turnaround time that day, by processing lab */
    public function turnaroundBreaches(string $organizationId, CarbonImmutable $date): array
    {
        [$fromUtc, $untilUtc] = $this->bounds($date);

        return $this->currentScope->runAs(ScopeContext::system($organizationId), fn (): array => WorklistEntry::query()
            ->where('tat_alerted_at', '>=', $fromUtc)
            ->where('tat_alerted_at', '<', $untilUtc)
            ->get(['processing_branch_id'])
            ->countBy('processing_branch_id')
            ->all());
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function bounds(CarbonImmutable $date): array
    {
        $fromUtc = CarbonImmutable::parse($date->toDateString(), self::BUSINESS_TIMEZONE)->utc();

        return [$fromUtc, $fromUtc->addDay()];
    }
}
