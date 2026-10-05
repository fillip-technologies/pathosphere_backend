<?php

namespace App\Modules\Catalogue\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Catalogue\Http\Requests\PackageRequest;
use App\Modules\Catalogue\Http\Resources\PackageResource;
use App\Modules\Catalogue\Models\Package;
use App\Modules\Catalogue\Services\CatalogueService;
use App\Modules\Shared\Http\Concurrency\EntityTag;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PackageController
{
    public function __construct(private readonly CatalogueService $catalogue) {}

    public function index(Request $request): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters(['is_active' => 'is_active'])
            ->allowSorts(['name', 'code'])
            ->allowSearch(fn ($query, string $text) => $query->where('name', 'like', "%{$text}%"))
            ->apply(Package::query()->with('tests'));

        return CursorPage::respond($query, $request, PackageResource::class);
    }

    public function store(PackageRequest $request, StaffContext $staff): Response
    {
        $package = $this->catalogue->createPackage($staff->user()->organization_id, $request->validated());

        return ApiResponse::created(PackageResource::make($package), "/api/v1/packages/{$package->id}");
    }

    public function show(Package $package): Response
    {
        return EntityTag::attach(PackageResource::make($package->load('tests'))->response(), $package);
    }

    public function update(PackageRequest $request, Package $package): Response
    {
        EntityTag::assertIfMatch($request, $package);
        $package = $this->catalogue->updatePackage($package, $request->validated());

        return EntityTag::attach(PackageResource::make($package)->response(), $package);
    }

    public function destroy(Package $package): Response
    {
        $this->catalogue->deletePackage($package);

        return ApiResponse::noContent();
    }
}
