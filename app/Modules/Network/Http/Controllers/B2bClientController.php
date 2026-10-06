<?php

namespace App\Modules\Network\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Network\Enums\B2bClientStatus;
use App\Modules\Network\Http\Requests\B2bClientRequest;
use App\Modules\Network\Http\Resources\B2bClientResource;
use App\Modules\Network\Models\B2bClient;
use App\Modules\Network\Services\B2bClientService;
use App\Modules\Shared\Http\Concurrency\EntityTag;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** B2B clients (spec §8 B2B). Clients are put on hold or closed, never deleted. */
final class B2bClientController
{
    public function __construct(private readonly B2bClientService $clients) {}

    public function index(Request $request): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters([
                'status' => 'status',
                'client_type' => 'client_type',
                'region_id' => 'region_id',
                'serviced_by_branch_id' => 'serviced_by_branch_id',
            ])
            ->allowSorts(['name', 'client_code', 'created_at'])
            ->allowSearch(fn ($query, string $text) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$text}%")
                ->orWhere('client_code', 'like', "{$text}%")))
            ->apply(B2bClient::query());

        return CursorPage::respond($query, $request, B2bClientResource::class);
    }

    public function store(B2bClientRequest $request, StaffContext $staff): Response
    {
        $client = $this->clients->create($staff, $request->validated());

        return ApiResponse::created(B2bClientResource::make($client), "/api/v1/b2b-clients/{$client->id}");
    }

    public function show(B2bClient $b2bClient): Response
    {
        return EntityTag::attach(B2bClientResource::make($b2bClient)->response(), $b2bClient);
    }

    public function update(B2bClientRequest $request, StaffContext $staff, B2bClient $b2bClient): Response
    {
        EntityTag::assertIfMatch($request, $b2bClient);
        $client = $this->clients->update($staff, $b2bClient, $request->validated());

        return EntityTag::attach(B2bClientResource::make($client)->response(), $client);
    }

    public function hold(B2bClient $b2bClient): Response
    {
        return $this->respondWithStatus($b2bClient, B2bClientStatus::OnHold);
    }

    public function activate(B2bClient $b2bClient): Response
    {
        return $this->respondWithStatus($b2bClient, B2bClientStatus::Active);
    }

    public function close(B2bClient $b2bClient): Response
    {
        return $this->respondWithStatus($b2bClient, B2bClientStatus::Closed);
    }

    private function respondWithStatus(B2bClient $client, B2bClientStatus $status): Response
    {
        $client = $this->clients->changeStatus($client, $status);

        return EntityTag::attach(B2bClientResource::make($client)->response(), $client);
    }
}
