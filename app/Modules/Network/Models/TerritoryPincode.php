<?php

namespace App\Modules\Network\Models;

use App\Modules\Shared\Models\BaseModel;

/**
 * One exclusive pincode of an agreement's territory (spec §6: the
 * territory_pincodes array as a child table). Read through its agreement,
 * which carries the scope.
 *
 * @property string $id
 * @property string $agreement_id
 * @property string $pincode
 */
final class TerritoryPincode extends BaseModel
{
    protected $table = 'franchise_territory_pincodes';

    public function recordsActor(): bool
    {
        return false;
    }
}
