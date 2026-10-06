<?php

namespace App\Modules\Samples\Http\Controllers;

use App\Modules\Auth\Services\StaffContext;
use App\Modules\Samples\Http\Requests\InventoryItemRequest;
use App\Modules\Samples\Http\Resources\InventoryItemResource;
use App\Modules\Samples\Models\InventoryItem;
use App\Modules\Samples\Services\InventoryService;
use App\Modules\Shared\Http\Concurrency\EntityTag;
use App\Modules\Shared\Http\Pagination\CursorPage;
use App\Modules\Shared\Http\Pagination\ListQuery;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Branch stock (spec §8 Inventory). */
final class InventoryItemController
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function index(Request $request): Response
    {
        $query = ListQuery::from($request)
            ->allowFilters([
                'branch_id' => 'branch_id',
                'category' => 'category',
                'item_code' => 'item_code',
                'expiring_before' => fn ($query, string $date) => $query->whereNotNull('expiry_date')->where('expiry_date', '<', $date),
                'below_reorder_level' => fn ($query, string $value) => filter_var($value, FILTER_VALIDATE_BOOLEAN)
                    ? $query->whereNotNull('reorder_level')->whereColumn('quantity', '<', 'reorder_level')
                    : $query,
            ])
            ->allowSorts(['item_code', 'expiry_date', 'created_at'])
            ->allowSearch(fn ($query, string $text) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$text}%")
                ->orWhere('item_code', 'like', "{$text}%")))
            ->apply(InventoryItem::query());

        return CursorPage::respond($query, $request, InventoryItemResource::class);
    }

    public function store(InventoryItemRequest $request, StaffContext $staff): Response
    {
        $item = $this->inventory->create($staff->user()->organization_id, $request->validated());

        return ApiResponse::created(InventoryItemResource::make($item), "/api/v1/inventory-items/{$item->id}");
    }

    public function show(InventoryItem $inventoryItem): Response
    {
        return EntityTag::attach(InventoryItemResource::make($inventoryItem)->response(), $inventoryItem);
    }

    public function update(InventoryItemRequest $request, InventoryItem $inventoryItem): Response
    {
        EntityTag::assertIfMatch($request, $inventoryItem);
        $item = $this->inventory->update($inventoryItem, $request->validated());

        return EntityTag::attach(InventoryItemResource::make($item)->response(), $item);
    }

    public function destroy(InventoryItem $inventoryItem): Response
    {
        $this->inventory->delete($inventoryItem);

        return ApiResponse::noContent();
    }
}
