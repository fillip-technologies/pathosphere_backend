<?php

namespace App\Modules\Locker\Models;

use App\Modules\Locker\Enums\DataTransferStatus;
use App\Modules\Shared\Models\BaseModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ABDM health-information request: what was asked under which consent,
 * where the data went and whether it arrived (spec §10: log every share).
 *
 * @property string $id
 * @property string $transaction_id
 * @property string $request_id
 * @property string|null $consent_id
 * @property string $consent_artefact_id
 * @property string $hip_id
 * @property CarbonImmutable $date_from
 * @property CarbonImmutable $date_to
 * @property string $data_push_url
 * @property array{crypto_algorithm: string, curve: string, public_key: string, nonce: string, expires_at: string|null} $key_material
 * @property DataTransferStatus $status
 * @property string|null $error_code
 * @property int $care_context_count
 * @property CarbonImmutable|null $transferred_at
 * @property Consent|null $consent
 */
final class AbdmDataTransfer extends BaseModel
{
    /** Mirrors the column defaults, so new models report what the database stores. */
    protected $attributes = ['care_context_count' => 0];

    protected function casts(): array
    {
        return [
            'date_from' => 'immutable_datetime',
            'date_to' => 'immutable_datetime',
            'key_material' => 'array',
            'status' => DataTransferStatus::class,
            'care_context_count' => 'integer',
            'transferred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Consent, $this> */
    public function consent(): BelongsTo
    {
        return $this->belongsTo(Consent::class);
    }

    /** Created by ABDM's request, not by a person. */
    public function recordsActor(): bool
    {
        return false;
    }
}
