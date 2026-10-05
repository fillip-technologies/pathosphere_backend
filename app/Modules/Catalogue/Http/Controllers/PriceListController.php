<?php

namespace App\Modules\Catalogue\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Catalogue\Http\Requests\PriceListImportRequest;
use App\Modules\Catalogue\Http\Requests\PriceListItemsRequest;
use App\Modules\Catalogue\Http\Requests\PriceListRequest;
use App\Modules\Catalogue\Http\Resources\PriceListItemResource;
use App\Modules\Catalogue\Http\Resources\PriceListResource;
use App\Modules\Catalogue\Models\PriceList;
use App\Modules\Catalogue\Services\PriceListService;
use App\Modules\Shared\Http\Concurrency\EntityTag;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PriceListController
{
    public function __construct(private readonly PriceListService $priceLists) {}

    public function index(Request $request): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters(['list_type' => 'list_type', 'is_active' => 'is_active', 'is_default_mrp' => 'is_default_mrp'])
            ->allowSorts(['name', 'valid_from'])
            ->apply(PriceList::query());

        return CursorPage::respond($query, $request, PriceListResource::class);
    }

    public function store(PriceListRequest $request, StaffContext $staff): Response
    {
        $priceList = $this->priceLists->create($staff->user()->organization_id, $request->validated());

        return ApiResponse::created(PriceListResource::make($priceList), "/api/v1/price-lists/{$priceList->id}");
    }

    public function show(PriceList $priceList): Response
    {
        return EntityTag::attach(PriceListResource::make($priceList)->response(), $priceList);
    }

    public function update(PriceListRequest $request, PriceList $priceList): Response
    {
        EntityTag::assertIfMatch($request, $priceList);
        $priceList = $this->priceLists->update($priceList, $request->validated());

        return EntityTag::attach(PriceListResource::make($priceList)->response(), $priceList);
    }

    public function destroy(PriceList $priceList): Response
    {
        $this->priceLists->delete($priceList);

        return ApiResponse::noContent();
    }

    public function items(Request $request, PriceList $priceList): Response
    {
        return CursorPage::respond($priceList->items()->with(['test', 'package'])->getQuery(), $request, PriceListItemResource::class);
    }

    public function replaceItems(PriceListItemsRequest $request, PriceList $priceList): JsonResponse
    {
        EntityTag::assertIfMatch($request, $priceList);
        $count = $this->priceLists->replaceItems($priceList, $request->items());

        return new JsonResponse(['data' => ['price_list_id' => $priceList->id, 'item_count' => $count]]);
    }

    public function import(PriceListImportRequest $request, PriceList $priceList): JsonResponse
    {
        $result = $this->priceLists->importCsv($priceList, (string) $request->file('file')?->get());

        return new JsonResponse(['data' => ['price_list_id' => $priceList->id, ...$result]]);
    }
}
