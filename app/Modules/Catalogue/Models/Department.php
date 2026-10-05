<?php

namespace App\Modules\Catalogue\Models;

use App\Modules\Catalogue\Enums\SigningDiscipline;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Biochemistry, haematology, microbiology … A department decides who may
 * sign its results (spec §7.2).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property SigningDiscipline $signing_discipline
 * @property int $report_order
 */
final class Department extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;
    use SoftDeletes;

    /** Mirrors the column defaults, so new models report what the database stores. */
    protected $attributes = ['report_order' => 0];

    protected function casts(): array
    {
        return [
            'signing_discipline' => SigningDiscipline::class,
            'report_order' => 'integer',
        ];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(visibleToWholeOrganization: true);
    }
}
