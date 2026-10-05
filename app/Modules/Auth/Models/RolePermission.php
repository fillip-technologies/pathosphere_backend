<?php

namespace App\Modules\Auth\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** Pivot row of role_permissions; it has its own UUID key. */
final class RolePermission extends Pivot
{
    use HasUuids;

    public $incrementing = false;

    protected $table = 'role_permissions';

    protected $dateFormat = 'Y-m-d H:i:s.u';
}
