<?php

namespace App\Modules\Lab\Models;

use App\Modules\Lab\Enums\ResultFlag;
use App\Modules\Lab\Enums\ResultSource;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One parameter result of one run (spec §7.6). Transaction table: reruns add
 * rows with the next run number; only the final run prints.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $worklist_entry_id
 * @property string $processing_branch_id
 * @property string $sample_id
 * @property string $order_item_id
 * @property string $test_parameter_id
 * @property int $run_no
 * @property string|null $value
 * @property string|null $value_numeric
 * @property string|null $unit
 * @property string|null $ref_range_text
 * @property ResultFlag|null $flag
 * @property bool $is_critical
 * @property string|null $instrument
 * @property ResultSource $source
 * @property string|null $comment
 * @property string|null $entered_by
 * @property CarbonImmutable $entered_at
 * @property string|null $verified_by
 * @property CarbonImmutable|null $verified_at
 * @property bool $is_final
 * @property WorklistEntry $worklistEntry
 */
final class LabResult extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;

    /** Mirrors the column defaults, so new models report what the database stores. */
    protected $attributes = ['run_no' => 1, 'is_critical' => false, 'is_final' => false];

    protected function casts(): array
    {
        return [
            'run_no' => 'integer',
            'flag' => ResultFlag::class,
            'is_critical' => 'boolean',
            'source' => ResultSource::class,
            'entered_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'is_final' => 'boolean',
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(processingBranch: 'processing_branch_id');
    }

    /** @return BelongsTo<WorklistEntry, $this> */
    public function worklistEntry(): BelongsTo
    {
        return $this->belongsTo(WorklistEntry::class);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
