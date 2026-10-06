<?php

namespace App\Modules\Locker\Models;

use App\Modules\Shared\Models\BaseModel;

/**
 * Kind of health record (spec §7.11): lab report, prescription, discharge
 * summary, imaging, vaccination, bill, other. Looked up by `code`.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $icon
 * @property int $sort_order
 */
final class RecordCategory extends BaseModel
{
    public const LAB_REPORT = 'lab_report';

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }
}
