<?php

namespace App\Modules\Lab\Models;

use App\Modules\Lab\Enums\WorklistStatus;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One ordered test at the lab that runs it, from the moment its sample is
 * received there. Seen only by that lab: the booking side follows progress
 * through the order and its reports.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $order_id
 * @property string $order_item_id
 * @property string $sample_id
 * @property string $processing_branch_id
 * @property string $test_id
 * @property string $department_id
 * @property int $current_run
 * @property CarbonImmutable|null $due_at
 * @property WorklistStatus $status
 * @property CarbonImmutable|null $tat_alerted_at
 * @property Collection<int, LabResult> $results
 */
final class WorklistEntry extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;

    /** Mirrors the column defaults, so new models report what the database stores. */
    protected $attributes = ['current_run' => 1];

    protected function casts(): array
    {
        return [
            'current_run' => 'integer',
            'due_at' => 'immutable_datetime',
            'status' => WorklistStatus::class,
            'tat_alerted_at' => 'immutable_datetime',
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(processingBranch: 'processing_branch_id');
    }

    /** @return HasMany<LabResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(LabResult::class);
    }
}
