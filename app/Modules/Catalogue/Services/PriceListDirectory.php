<?php

namespace App\Modules\Catalogue\Services;

use App\Modules\Catalogue\Enums\PriceListType;
use App\Modules\Catalogue\Models\PriceList;

/** Questions other modules ask about price lists. */
final class PriceListDirectory
{
    public function isListOfType(string $priceListId, PriceListType $type): bool
    {
        return PriceList::query()->whereKey($priceListId)->where('list_type', $type)->exists();
    }
}
