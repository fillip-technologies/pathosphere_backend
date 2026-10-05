<?php

namespace App\Modules\Catalogue\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Catalogue\Http\Requests\LabTestRequest;
use App\Modules\Catalogue\Http\Resources\LabTestResource;
use App\Modules\Catalogue\Models\LabTest;
use App\Modules\Catalogue\Services\CatalogueService;
use App\Modules\Shared\Http\Concurrency\EntityTag;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LabTestController
{
    public function __construct(private readonly CatalogueService $catalogue) {}

    public function index(Request $request): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters([
                'department_id' => 'department_id',
                'is_active' => 'is_active',
                'sample_type' => 'sample_type',
                'container_type' => 'container_type',
            ])
            ->allowSorts(['name', 'code', 'tat_hours'])
            ->allowSearch(fn ($query, string $text) => $query->where(fn ($inner) => $inner
                ->where('code', 'like', "{$text}%")
                ->orWhere('name', 'like', "%{$text}%")))
            ->apply(LabTest::query());

        return CursorPage::respond($query, $request, LabTestResource::class);
    }

    public function store(LabTestRequest $request, StaffContext $staff): Response
    {
        $test = $this->catalogue->createTest($staff->user()->organization_id, $request->validated());

        return ApiResponse::created(LabTestResource::make($test), "/api/v1/tests/{$test->id}");
    }

    public function show(LabTest $test): Response
    {
        $test->load('parameters.referenceRanges');

        return EntityTag::attach(LabTestResource::make($test)->response(), $test);
    }

    public function update(LabTestRequest $request, LabTest $test): Response
    {
        EntityTag::assertIfMatch($request, $test);
        $test = $this->catalogue->updateTest($test, $request->validated());

        return EntityTag::attach(LabTestResource::make($test)->response(), $test);
    }

    public function destroy(LabTest $test): Response
    {
        $this->catalogue->deleteTest($test);

        return ApiResponse::noContent();
    }
}
