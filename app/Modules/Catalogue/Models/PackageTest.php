<?php

namespace App\Modules\Catalogue\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

final class PackageTest extends Pivot
{
    use HasUuids;

    public $incrementing = false;

    protected $table = 'package_tests';

    protected $dateFormat = 'Y-m-d H:i:s.u';
}
