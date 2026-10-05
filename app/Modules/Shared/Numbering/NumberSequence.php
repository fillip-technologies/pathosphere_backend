<?php

namespace App\Modules\Shared\Numbering;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One counter per (organization, series, financial year). Only
 * NumberSequenceService reads or writes it.
 *
 * @property string $id
 * @property int $next_value
 */
final class NumberSequence extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['next_value' => 'integer'];
    }
}
