<?php

namespace App\Modules\Catalogue\Models;

use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Money\Money;
use App\Modules\Shared\Money\MoneyCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The price of one test or one package on a list.
 *
 * @property string $id
 * @property string $price_list_id
 * @property string|null $test_id
 * @property string|null $package_id
 * @property Money $price
 */
final class PriceListItem extends BaseModel
{
    protected function casts(): array
    {
        return ['price' => MoneyCast::class];
    }

    /** @return BelongsTo<LabTest, $this> */
    public function test(): BelongsTo
    {
        return $this->belongsTo(LabTest::class, 'test_id');
    }

    /** @return BelongsTo<Package, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
