<?php

namespace App\Modules\Catalogue\Models;

use App\Modules\Shared\Models\BaseModel;

/**
 * A lab can run a test (spec §7.4). Switched off during analyser breakdowns.
 * Always queried by branch IDs taken from scoped branch lookups.
 *
 * @property string $id
 * @property string $branch_id
 * @property string $test_id
 * @property bool $is_active
 * @property int|null $daily_capacity
 */
final class LabTestCapability extends BaseModel
{
    /** Mirrors the column defaults, so new models report what the database stores. */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'daily_capacity' => 'integer',
        ];
    }
}
