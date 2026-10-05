<?php

namespace App\Modules\Auth\Models;

use App\Modules\Auth\Permissions\Permission;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A row of the `permissions` table, seeded from the Permission enum. Code
 * checks the enum; this table exists so roles can reference permissions.
 *
 * @property string $id
 * @property string $name
 * @property string $module
 * @property string $description
 */
final class PermissionEntry extends Model
{
    use HasUuids;

    protected $table = 'permissions';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = [];

    public function permission(): Permission
    {
        return Permission::from($this->name);
    }
}
