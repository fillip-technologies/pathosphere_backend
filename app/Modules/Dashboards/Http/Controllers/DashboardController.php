<?php

namespace App\Modules\Dashboards\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Dashboards\Http\Requests\DashboardRequest;
use App\Modules\Dashboards\Models\DailyBranchMetric;
use App\Modules\Dashboards\Services\DashboardSummary;
use App\Modules\Network\Enums\PartnerType;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Network\Services\PartnerAccount;
use App\Modules\Network\Services\PartnerAccounts;
use App\Modules\Shared\Errors\DomainError;
use App\Modules\Shared\Errors\ErrorCode;
use App\Modules\Shared\Money\Money;
use Illuminate\Http\JsonResponse;

/**
 * GET /dashboards/hq, /region/{id}, /franchise/{id}, /branch/{id} (spec §8):
 * revenue, volume, TAT, rejections and dues, from the nightly summary. Each
 * row is also limited by the caller's scope, so a manager sees only their part.
 */
final class DashboardController
{
    public function __construct(
        private readonly DashboardSummary $summary,
        private readonly NetworkDirectory $network,
        private readonly PartnerAccounts $accounts,
    ) {}

    public function hq(DashboardRequest $request, StaffContext $staff): JsonResponse
    {
        $data = $this->summary->summarise(DailyBranchMetric::query(), $request->from(), $request->to());
        $data['dues'] = $this->partnerDues($staff->user()->organization_id);

        return $this->respond('organization', $staff->user()->organization_id, $data);
    }

    public function region(DashboardRequest $request, string $regionId): JsonResponse
    {
        $this->assertVisible($this->network->regionIsVisible($regionId));
        $metrics = DailyBranchMetric::query()->whereIn('region_id', $this->network->regionTreeIds($regionId));

        return $this->respond('region', $regionId, $this->summary->summarise($metrics, $request->from(), $request->to()));
    }

    public function franchise(DashboardRequest $request, StaffContext $staff, string $franchiseId): JsonResponse
    {
        $this->assertVisible($this->network->franchiseIsVisible($franchiseId));
        $data = $this->summary->summarise(DailyBranchMetric::query()->where('franchise_id', $franchiseId), $request->from(), $request->to());
        $account = $this->accounts->find($staff->user()->organization_id, PartnerType::Franchise, $franchiseId);
        $data['dues'] = $account === null ? null : $this->accountDues($account);

        return $this->respond('franchise', $franchiseId, $data);
    }

    public function branch(DashboardRequest $request, string $branchId): JsonResponse
    {
        $this->assertVisible($this->network->branchIsVisible($branchId));
        $metrics = DailyBranchMetric::query()->where('branch_id', $branchId);

        return $this->respond('branch', $branchId, $this->summary->summarise($metrics, $request->from(), $request->to()));
    }

    /** @return array<string, mixed> what partners owe HQ, and HQ them, right now (spec §8: dues) */
    private function partnerDues(string $organizationId): array
    {
        $owedToHq = Money::zero();
        $owedByHq = Money::zero();
        $partnersOwing = 0;

        foreach ($this->accounts->settlingPartners($organizationId) as $account) {
            if ($account->balance->isNegative()) {
                $owedToHq = $owedToHq->subtract($account->balance);
                $partnersOwing++;
            } elseif ($account->isFranchise()) {
                $owedByHq = $owedByHq->add($account->balance);
            }
        }

        return ['owed_to_hq' => $owedToHq, 'partners_owing' => $partnersOwing, 'partner_credit_held' => $owedByHq];
    }

    /** @return array<string, mixed> */
    private function accountDues(PartnerAccount $account): array
    {
        return [
            'balance' => $account->balance,
            'credit_limit' => $account->creditLimit,
            'billing_model' => $account->terms?->billingModel,
        ];
    }

    private function assertVisible(bool $visible): void
    {
        if (! $visible) {
            throw new DomainError(ErrorCode::NOT_FOUND, 'Not found.', 404);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function respond(string $level, string $id, array $data): JsonResponse
    {
        $latest = DailyBranchMetric::query()->max('metric_date');

        return new JsonResponse(['data' => [
            'scope' => ['level' => $level, 'id' => $id],
            ...$data,
            // Dashboards show the summary up to last night, never live figures.
            'summarised_through' => $latest === null ? null : substr((string) $latest, 0, 10),
        ]]);
    }
}
