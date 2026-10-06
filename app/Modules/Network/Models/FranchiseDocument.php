<?php

namespace App\Modules\Network\Models;

use App\Modules\Network\Enums\FranchiseDocumentStatus;
use App\Modules\Network\Enums\FranchiseDocumentType;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Carbon\CarbonImmutable;

/**
 * A KYC or compliance paper of a franchise, kept in private storage
 * (spec §7.1). Never deleted: a rejected paper is replaced by a new upload.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $franchise_id
 * @property FranchiseDocumentType $doc_type
 * @property string $file_path
 * @property string|null $vendor_reference
 * @property FranchiseDocumentStatus $status
 * @property string|null $rejection_note
 * @property string|null $verified_by
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $expires_on
 */
final class FranchiseDocument extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;

    protected function casts(): array
    {
        return [
            'doc_type' => FranchiseDocumentType::class,
            'status' => FranchiseDocumentStatus::class,
            'verified_at' => 'immutable_datetime',
            'expires_on' => 'immutable_date',
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(franchise: 'franchise_id');
    }
}
