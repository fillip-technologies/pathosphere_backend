<?php

namespace App\Modules\Samples\Models;

use App\Modules\Shared\Models\BaseModel;

/**
 * Junction: the order items (tests) one container serves (spec §7.6).
 * Read through its sample, which carries the scope.
 *
 * @property string $id
 * @property string $sample_id
 * @property string $order_item_id
 */
final class SampleOrderItem extends BaseModel
{
    protected $table = 'sample_order_items';

    public function recordsActor(): bool
    {
        return false;
    }
}
