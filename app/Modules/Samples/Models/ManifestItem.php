<?php

namespace App\Modules\Samples\Models;

use App\Modules\Samples\Enums\ManifestItemCondition;
use App\Modules\Shared\Models\BaseModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sample on a manifest and how it arrived (table `sample_transfer_items`).
 * A sample appears on several manifests when a lab forwards it onward.
 *
 * @property string $id
 * @property string $transfer_id
 * @property string $sample_id
 * @property ManifestItemCondition $condition
 * @property string|null $rejection_reason
 * @property CarbonImmutable|null $scanned_at
 * @property Sample $sample
 * @property Manifest $manifest
 */
final class ManifestItem extends BaseModel
{
    protected $table = 'sample_transfer_items';

    /** Mirrors the column default, so new models report what the database stores. */
    protected $attributes = ['condition' => 'pending'];

    protected function casts(): array
    {
        return [
            'condition' => ManifestItemCondition::class,
            'scanned_at' => 'immutable_datetime',
        ];
    }

    public function recordsActor(): bool
    {
        return false;
    }

    /** @return BelongsTo<Sample, $this> */
    public function sample(): BelongsTo
    {
        return $this->belongsTo(Sample::class);
    }

    /** @return BelongsTo<Manifest, $this> */
    public function manifest(): BelongsTo
    {
        return $this->belongsTo(Manifest::class, 'transfer_id');
    }
}
