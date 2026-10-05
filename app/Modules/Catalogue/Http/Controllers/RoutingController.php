<?php

namespace App\Modules\Catalogue\Http\Controllers;

use App\Modules\Catalogue\Http\Requests\CapabilitiesRequest;
use App\Modules\Catalogue\Http\Requests\RoutingResolutionRequest;
use App\Modules\Catalogue\Http\Requests\RoutingRuleRequest;
use App\Modules\Catalogue\Http\Resources\RoutingRuleResource;
use App\Modules\Catalogue\Models\LabTestCapability;
use App\Modules\Catalogue\Models\RoutingRule;
use App\Modules\Catalogue\Services\RoutingService;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Shared\Http\Concurrency\EntityTag;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Lab capabilities, routing rules and routing resolution (spec §8 Routing). */
final class RoutingController
{
    public function __construct(
        private readonly RoutingService $routing,
        private readonly NetworkDirectory $network,
    ) {}

    public function capabilities(string $branchId): JsonResponse
    {
        abort_unless($this->network->branchIsVisible($branchId), 404);

        $capabilities = LabTestCapability::query()->where('branch_id', $branchId)->get()
            ->map(fn (LabTestCapability $capability) => [
                'test_id' => $capability->test_id,
                'is_active' => $capability->is_active,
                'daily_capacity' => $capability->daily_capacity,
            ])->values()->all();

        return new JsonResponse(['data' => ['branch_id' => $branchId, 'capabilities' => $capabilities]]);
    }

    public function replaceCapabilities(CapabilitiesRequest $request, string $branchId): JsonResponse
    {
        abort_unless($this->network->branchIsVisible($branchId), 404);
        $count = $this->routing->replaceCapabilities($branchId, $request->capabilities());

        return new JsonResponse(['data' => ['branch_id' => $branchId, 'capability_count' => $count]]);
    }

    public function index(Request $request): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters([
                'source_branch_id' => 'source_branch_id',
                'processing_branch_id' => 'processing_branch_id',
                'test_id' => 'test_id',
                'is_active' => 'is_active',
            ])
            ->allowSorts(['priority', 'created_at'])
            ->apply(RoutingRule::query()->whereIn('source_branch_id', $this->network->visibleBranchIds()));

        return CursorPage::respond($query, $request, RoutingRuleResource::class);
    }

    public function store(RoutingRuleRequest $request): Response
    {
        $rule = $this->routing->createRule($request->validated());

        return ApiResponse::created(RoutingRuleResource::make($rule), "/api/v1/routing-rules/{$rule->id}");
    }

    public function show(RoutingRule $routingRule): Response
    {
        return EntityTag::attach(RoutingRuleResource::make($routingRule)->response(), $routingRule);
    }

    public function update(RoutingRuleRequest $request, RoutingRule $routingRule): Response
    {
        EntityTag::assertIfMatch($request, $routingRule);
        $rule = $this->routing->updateRule($routingRule, $request->validated());

        return EntityTag::attach(RoutingRuleResource::make($rule)->response(), $rule);
    }

    public function destroy(RoutingRule $routingRule): Response
    {
        $this->routing->deleteRule($routingRule);

        return ApiResponse::noContent();
    }

    /** GET /routing-resolutions?branch_id=…&test_ids[]=… */
    public function resolve(RoutingResolutionRequest $request): JsonResponse
    {
        $resolved = $this->routing->resolve($request->validated('branch_id'), $request->validated('test_ids'));

        return new JsonResponse(['data' => array_map(
            fn (string $testId, ?string $labId) => ['test_id' => $testId, 'processing_branch_id' => $labId],
            array_keys($resolved),
            array_values($resolved),
        )]);
    }
}
