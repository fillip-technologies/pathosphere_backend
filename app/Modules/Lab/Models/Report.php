<?php

namespace App\Modules\Lab\Models;

use App\Modules\Lab\Enums\ReportStatus;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One lab's report for one order, versioned (spec §7.6). A released version
 * is a legal record: its PDF is never regenerated, and corrections create the
 * next version while this one becomes `amended`.
 *
 * Seen by the lab that wrote it and, like the order, by the booking branch,
 * its franchise and its B2B client.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $order_id
 * @property string $patient_id
 * @property string $branch_id
 * @property string|null $franchise_id
 * @property string|null $b2b_client_id
 * @property string $processing_branch_id
 * @property int $version
 * @property ReportStatus $status
 * @property bool $is_partial
 * @property string|null $amendment_reason
 * @property string|null $pdf_path
 * @property string|null $pdf_sha256
 * @property string $qr_code
 * @property CarbonImmutable|null $released_at
 * @property string|null $released_by
 * @property Collection<int, ReportSignature> $signatures
 */
final class Report extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;

    /** Mirrors the column defaults, so new models report what the database stores. */
    protected $attributes = ['version' => 1, 'is_partial' => false];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => ReportStatus::class,
            'is_partial' => 'boolean',
            'released_at' => 'immutable_datetime',
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(
            franchise: 'franchise_id',
            branch: 'branch_id',
            processingBranch: 'processing_branch_id',
            b2bClient: 'b2b_client_id',
        );
    }

    /** @return HasMany<ReportSignature, $this> signatures still in force */
    public function signatures(): HasMany
    {
        return $this->hasMany(ReportSignature::class)->whereNull('revoked_at');
    }
}
