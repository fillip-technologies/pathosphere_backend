<?php

namespace App\Modules\Catalogue\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Catalogue\Http\Requests\DepartmentRequest;
use App\Modules\Catalogue\Http\Resources\DepartmentResource;
use App\Modules\Catalogue\Models\Department;
use App\Modules\Catalogue\Services\CatalogueService;
use App\Modules\Shared\Http\Concurrency\EntityTag;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DepartmentController
{
    public function __construct(private readonly CatalogueService $catalogue) {}

    public function index(Request $request): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters(['signing_discipline' => 'signing_discipline'])
            ->allowSorts(['name', 'report_order'])
            ->apply(Department::query());

        return CursorPage::respond($query, $request, DepartmentResource::class);
    }

    public function store(DepartmentRequest $request, StaffContext $staff): Response
    {
        $department = $this->catalogue->createDepartment($staff->user()->organization_id, $request->validated());

        return ApiResponse::created(DepartmentResource::make($department), "/api/v1/departments/{$department->id}");
    }

    public function show(Department $department): Response
    {
        return EntityTag::attach(DepartmentResource::make($department)->response(), $department);
    }

    public function update(DepartmentRequest $request, Department $department): Response
    {
        EntityTag::assertIfMatch($request, $department);
        $department = $this->catalogue->updateDepartment($department, $request->validated());

        return EntityTag::attach(DepartmentResource::make($department)->response(), $department);
    }

    public function destroy(Department $department): Response
    {
        $this->catalogue->deleteDepartment($department);

        return ApiResponse::noContent();
    }
}
