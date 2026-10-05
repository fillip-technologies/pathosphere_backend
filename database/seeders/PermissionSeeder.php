<?php

namespace Database\Seeders;

use App\Modules\Auth\Services\PermissionCatalogue;
use Illuminate\Database\Seeder;

/** Permissions and system roles, straight from the code catalogue. */
class PermissionSeeder extends Seeder
{
    public function run(PermissionCatalogue $catalogue): void
    {
        $catalogue->sync();
    }
}
