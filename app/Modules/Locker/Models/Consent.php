<?php

namespace App\Modules\Locker\Models;

use App\Modules\Locker\Enums\ConsentPurpose;
use App\Modules\Locker\Enums\ConsentStatus;
use App\Modules\Shared\Models\BaseModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Permission to see records (spec §7.11): a patient's own share with a
 * doctor, an email address or a link, and later ABDM consent artefacts.
 *
 * @property string $id
 * @property string $patient_id
 * @property string|null $consent_artefact_id
 * @property string $requester
 * @property ConsentPurpose $purpose
 * @property array{medical_record_ids: list<string>} $scope
 * @property ConsentStatus $status
 * @property CarbonImmutable|null $granted_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable $created_at
 * @property Collection<int, RecordShare> $shares
 */
final class Consent extends BaseModel
{
    protected function casts(): array
    {
        return [
            'purpose' => ConsentPurpose::class,
            'scope' => 'array',
            'status' => ConsentStatus::class,
            'granted_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<RecordShare, $this> */
    public function shares(): HasMany
    {
        return $this->hasMany(RecordShare::class)->orderBy('id');
    }

    /** Spec §7.11: granted and not expired. */
    public function isInForce(?CarbonImmutable $at = null): bool
    {
        return $this->status === ConsentStatus::Granted
            && ($this->expires_at === null || $this->expires_at->isAfter($at ?? CarbonImmutable::now()));
    }
}
