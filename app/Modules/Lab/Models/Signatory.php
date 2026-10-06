<?php

namespace App\Modules\Lab\Models;

use App\Modules\Catalogue\Enums\SigningDiscipline;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A doctor allowed to sign one department's results at one lab (spec §7.2).
 * A doctor signing at several labs has one row per lab and department.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property string $branch_id
 * @property string $department_id
 * @property SigningDiscipline $signing_discipline
 * @property string $qualification
 * @property string $council_name
 * @property string $registration_no
 * @property string|null $hpr_id
 * @property string $signature_image_path
 * @property CarbonImmutable|null $valid_till
 * @property bool $is_active
 */
final class Signatory extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;
    use SoftDeletes;

    protected $hidden = ['signature_image_path'];

    /** Mirrors the column defaults, so new models report what the database stores. */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'signing_discipline' => SigningDiscipline::class,
            'valid_till' => 'immutable_date',
            'is_active' => 'boolean',
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(branch: 'branch_id');
    }
}
