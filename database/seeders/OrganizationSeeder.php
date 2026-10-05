<?php

namespace Database\Seeders;

use App\Modules\Network\Enums\OrganizationStatus;
use App\Modules\Network\Models\Organization;
use Illuminate\Database\Seeder;

/** The single head-office organization of a v1 install (spec §12). */
class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        if (Organization::query()->exists()) {
            return;
        }

        $organization = new Organization([
            'name' => config('pathology.organization.name'),
            'legal_name' => config('pathology.organization.legal_name'),
            'hq_address' => config('pathology.organization.hq_address'),
            'settings' => [],
        ]);
        $organization->status = OrganizationStatus::Active;
        $organization->save();
    }
}
