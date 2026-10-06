<?php

namespace App\Modules\Locker\Models;

use App\Modules\Locker\Enums\ShareStatus;
use App\Modules\Locker\Enums\ShareTarget;
use App\Modules\Shared\Models\BaseModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One record made visible under a consent (spec §7.11). Usable only while it
 * is active, unexpired and its consent is granted and unexpired.
 *
 * @property string $id
 * @property string $medical_record_id
 * @property string $consent_id
 * @property ShareTarget $shared_with_type
 * @property string $shared_with_ref
 * @property string|null $access_token_hash
 * @property CarbonImmutable $expires_at
 * @property ShareStatus $status
 * @property Consent $consent
 * @property MedicalRecord $record
 */
final class RecordShare extends BaseModel
{
    protected $hidden = ['access_token_hash'];

    protected function casts(): array
    {
        return [
            'shared_with_type' => ShareTarget::class,
            'expires_at' => 'immutable_datetime',
            'status' => ShareStatus::class,
        ];
    }

    /** @return BelongsTo<Consent, $this> */
    public function consent(): BelongsTo
    {
        return $this->belongsTo(Consent::class);
    }

    /** @return BelongsTo<MedicalRecord, $this> */
    public function record(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class, 'medical_record_id');
    }

    /**
     * Shares that can be used right now: the share and its consent both in force.
     *
     * @param  Builder<RecordShare>  $query
     */
    public function scopeUsable(Builder $query, CarbonImmutable $at): void
    {
        $query->where('record_shares.status', ShareStatus::Active)
            ->where('record_shares.expires_at', '>', $at)
            ->whereHas('consent', fn (Builder $consents) => $consents
                ->where('status', 'granted')
                ->where(fn (Builder $expiry) => $expiry->whereNull('expires_at')->orWhere('expires_at', '>', $at)));
    }

    public function isUsable(CarbonImmutable $at): bool
    {
        return $this->status === ShareStatus::Active && $this->expires_at->isAfter($at) && $this->consent->isInForce($at);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
