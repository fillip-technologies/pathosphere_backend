<?php

namespace App\Modules\Booking\Models;

use App\Modules\Booking\Enums\AbdmDirection;
use App\Modules\Booking\Enums\AbdmRequestStatus;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Every call to and callback from ABDM (spec §5.7). Payloads are stored with
 * OTPs, Aadhaar numbers and tokens removed.
 *
 * @property string $id
 * @property string|null $patient_id
 * @property string|null $branch_id
 * @property AbdmDirection $direction
 * @property string $api_name
 * @property string $request_id
 * @property string|null $txn_id
 * @property int|null $http_status
 * @property AbdmRequestStatus $status
 * @property string|null $error_code
 * @property array<string, mixed>|null $payload_masked
 * @property string|null $requested_by
 * @property CarbonImmutable $created_at
 */
final class AbdmRequest extends Model implements HasScopeColumns
{
    use BelongsToScope;
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'direction' => AbdmDirection::class,
            'status' => AbdmRequestStatus::class,
            'http_status' => 'integer',
            'payload_masked' => 'array',
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(organization: null, branch: 'branch_id');
    }
}
