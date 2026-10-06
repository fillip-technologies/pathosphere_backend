<?php

namespace App\Modules\Lab\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Lab\Http\Requests\CreateInterfaceAgentRequest;
use App\Modules\Lab\Http\Resources\InterfaceAgentResource;
use App\Modules\Lab\Models\InterfaceAgent;
use App\Modules\Lab\Services\InterfaceAgentService;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** HQ issues and revokes the API keys of each lab's analyser interface. */
final class InterfaceAgentController
{
    public function __construct(private readonly InterfaceAgentService $agents) {}

    public function index(Request $request): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters(['branch_id' => 'branch_id'])
            ->apply(InterfaceAgent::query());

        return CursorPage::respond($query, $request, InterfaceAgentResource::class);
    }

    /** The key is in this response only; store it in the agent's configuration. */
    public function store(CreateInterfaceAgentRequest $request, StaffContext $staff): Response
    {
        [$agent, $key] = $this->agents->create(
            $staff->user()->organization_id,
            $request->validated('branch_id'),
            $request->validated('name'),
            array_values($request->validated('allowed_ips')),
        );

        return new JsonResponse(
            ['data' => [...InterfaceAgentResource::make($agent)->toArray($request), 'api_key' => $key]],
            201,
            ['Location' => "/api/v1/interface-agents/{$agent->id}"],
        );
    }

    public function revoke(InterfaceAgent $interfaceAgent): Response
    {
        return InterfaceAgentResource::make($this->agents->revoke($interfaceAgent))->response();
    }
}
