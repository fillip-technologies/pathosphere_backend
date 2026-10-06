<?php

namespace App\Modules\Auth\Models;

use App\Modules\Auth\Enums\OtpPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One code sent to a phone (spec §7.9). Only a keyed hash of the code is
 * stored (spec §10.3); a code is good for 5 minutes and 5 tries.
 *
 * @property string $id
 * @property string|null $account_id
 * @property string $phone
 * @property string $code_hash
 * @property OtpPurpose $purpose
 * @property CarbonImmutable $expires_at
 * @property int $attempts
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable $created_at
 */
final class OtpVerification extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = [];

    /** Mirrors the column defaults, so new models report what the database stores. */
    protected $attributes = ['attempts' => 0];

    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'expires_at' => 'immutable_datetime',
            'attempts' => 'integer',
            'verified_at' => 'immutable_datetime',
        ];
    }
}
