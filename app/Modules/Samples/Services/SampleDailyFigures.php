<?php

namespace App\Modules\Samples\Services;

use App\Modules\Samples\Enums\SampleStatus;
use App\Modules\Samples\Models\Sample;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Carbon\CarbonImmutable;

/** Sample totals per branch for one business day, for the nightly dashboard summary. */
final class SampleDailyFigures
{
    private const BUSINESS_TIMEZONE = 'Asia/Kolkata';

    public function __construct(private readonly CurrentScope $currentScope) {}

    /**
     * Samples rejected that day, by the branch that drew them (spec §11:
     * rejection rate by collecting branch). Rejection is terminal, so a
     * rejected sample's last update is its rejection.
     *
     * @return array<string, int> by branch ID
     */
    public function rejectedByCollectingBranch(string $organizationId, CarbonImmutable $date): array
    {
        $fromUtc = CarbonImmutable::parse($date->toDateString(), self::BUSINESS_TIMEZONE)->utc();

        return $this->currentScope->runAs(ScopeContext::system($organizationId), fn (): array => Sample::query()
            ->where('status', SampleStatus::Rejected)
            ->where('updated_at', '>=', $fromUtc)
            ->where('updated_at', '<', $fromUtc->addDay())
            ->get(['collected_branch_id'])
            ->countBy('collected_branch_id')
            ->all());
    }
}
