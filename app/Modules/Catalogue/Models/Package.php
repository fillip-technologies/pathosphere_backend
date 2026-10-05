<?php

namespace App\Modules\Catalogue\Models;

use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Scoping\BelongsToScope;
use App\Modules\Shared\Scoping\HasScopeColumns;
use App\Modules\Shared\Scoping\ScopeColumns;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A bundle of tests sold at one price (spec §7.4).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property Collection<int, LabTest> $tests
 */
final class Package extends BaseModel implements HasScopeColumns
{
    use BelongsToScope;
    use SoftDeletes;

    /** Mirrors the column defaults, so new models report what the database stores. */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public static function scopeColumns(): ScopeColumns
    {
        return new ScopeColumns(visibleToWholeOrganization: true);
    }

    /** @return BelongsToMany<LabTest, $this, PackageTest> */
    public function tests(): BelongsToMany
    {
        return $this->belongsToMany(LabTest::class, 'package_tests', 'package_id', 'test_id')
            ->using(PackageTest::class)
            ->withTimestamps();
    }
}
