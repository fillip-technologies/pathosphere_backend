<?php

namespace App\Modules\Network\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Network\Http\Requests\RegionRequest;
use App\Modules\Network\Http\Resources\RegionResource;
use App\Modules\Network\Models\Region;
use App\Modules\Network\Services\RegionService;
use App\Modules\Shared\Http\Concurrency\EntityTag;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RegionController
{
    public function __construct(private readonly RegionService $regions) {}

    public function index(Request $request): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters(['parent_region_id' => 'parent_region_id', 'region_type' => 'region_type'])
            ->allowSorts(['name', 'created_at'])
            ->allowSearch(fn ($query, string $text) => $query->where('name', 'like', "%{$text}%"))
            ->apply(Region::query());

        return CursorPage::respond($query, $request, RegionResource::class);
    }

    public function store(RegionRequest $request, StaffContext $staff): Response
    {
        $region = $this->regions->create($staff->user()->organization_id, $request->validated());

        return ApiResponse::created(RegionResource::make($region), "/api/v1/regions/{$region->id}");
    }

    /** A region with its direct children, so clients can walk the tree. */
    public function show(Region $region): Response
    {
        $region->load('children');

        return EntityTag::attach(RegionResource::make($region)->response(), $region);
    }

    public function update(RegionRequest $request, Region $region): Response
    {
        EntityTag::assertIfMatch($request, $region);
        $region = $this->regions->update($region, $request->validated());

        return EntityTag::attach(RegionResource::make($region)->response(), $region);
    }

    public function destroy(Region $region): Response
    {
        $this->regions->delete($region);

        return ApiResponse::noContent();
    }
}
