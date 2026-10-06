<?php

namespace App\Modules\Lab\Models;

use App\Modules\Shared\Models\BaseModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A department's section of a report signed by an eligible signatory
 * (spec §7.6). Read through its report, which carries the scope. Revoked,
 * never deleted, when that department's results change before release.
 *
 * @property string $id
 * @property string $report_id
 * @property string $signatory_id
 * @property string $department_id
 * @property CarbonImmutable $signed_at
 * @property string|null $signer_ip
 * @property CarbonImmutable|null $revoked_at
 * @property Signatory $signatory
 */
final class ReportSignature extends BaseModel
{
    protected function casts(): array
    {
        return [
            'signed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function recordsActor(): bool
    {
        return false;
    }

    /** @return BelongsTo<Signatory, $this> including signatories since removed: the signature stays valid */
    public function signatory(): BelongsTo
    {
        return $this->belongsTo(Signatory::class)->withTrashed();
    }
}
