<?php

namespace App\Modules\Catalogue\Http\Controllers;

use App\Modules\Catalogue\Http\Requests\TestParameterRequest;
use App\Modules\Catalogue\Http\Resources\TestParameterResource;
use App\Modules\Catalogue\Models\LabTest;
use App\Modules\Catalogue\Models\TestParameter;
use App\Modules\Catalogue\Services\CatalogueService;
use App\Modules\Shared\Http\Concurrency\EntityTag;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** /tests/{test}/parameters: analytes with their reference ranges. */
final class TestParameterController
{
    public function __construct(private readonly CatalogueService $catalogue) {}

    public function index(Request $request, LabTest $test): Response
    {
        return CursorPage::respond($test->parameters()->with('referenceRanges')->getQuery(), $request, TestParameterResource::class);
    }

    public function store(TestParameterRequest $request, LabTest $test): Response
    {
        $parameter = $this->catalogue->addParameter($test, $request->validated());

        return ApiResponse::created(TestParameterResource::make($parameter), "/api/v1/tests/{$test->id}/parameters/{$parameter->id}");
    }

    public function show(LabTest $test, TestParameter $parameter): Response
    {
        $parameter->load('referenceRanges');

        return EntityTag::attach(TestParameterResource::make($parameter)->response(), $parameter);
    }

    public function update(TestParameterRequest $request, LabTest $test, TestParameter $parameter): Response
    {
        EntityTag::assertIfMatch($request, $parameter);
        $parameter = $this->catalogue->updateParameter($parameter, $request->validated());

        return EntityTag::attach(TestParameterResource::make($parameter)->response(), $parameter);
    }

    public function destroy(LabTest $test, TestParameter $parameter): Response
    {
        $this->catalogue->deleteParameter($parameter);

        return ApiResponse::noContent();
    }
}
