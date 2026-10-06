<?php

namespace Database\Seeders;

use App\Modules\Network\Enums\BranchType;
use App\Modules\Network\Enums\RegionType;
use App\Modules\Network\Models\Branch;
use App\Modules\Network\Models\Franchise;
use App\Modules\Network\Models\Organization;
use App\Modules\Network\Models\Region;
use Illuminate\Database\Seeder;

/**
 * Demo network from spec §11.6: two regions, one reference lab, two own
 * clinical labs, three own PSCs, and two franchises with three PSCs between
 * them. B2B clients are added in Phase 2, once client price lists exist.
 */
class DevelopmentNetworkSeeder extends Seeder
{
    public function run(): void
    {
        if (Branch::query()->exists()) {
            return;
        }

        $organization = Organization::query()->firstOrFail();

        $bihar = $this->region($organization, 'Bihar');
        $patna = $this->region($organization, 'Patna', $bihar);
        $jharkhand = $this->region($organization, 'Jharkhand');
        $ranchi = $this->region($organization, 'Ranchi', $jharkhand);

        Branch::factory()->in($patna)->ofType(BranchType::ReferenceLab)->create(['branch_code' => 'PATREF', 'hfr_id' => 'DEMO-HFR-PATREF', 'name' => 'Patna Reference Lab', 'pincode' => '800001']);
        Branch::factory()->in($patna)->ofType(BranchType::ClinicalLab)->create(['branch_code' => 'PATCL1', 'hfr_id' => 'DEMO-HFR-PATCL1', 'name' => 'Patna Clinical Lab', 'pincode' => '800013']);
        Branch::factory()->in($ranchi)->ofType(BranchType::ClinicalLab)->create(['branch_code' => 'RNCCL1', 'hfr_id' => 'DEMO-HFR-RNCCL1', 'name' => 'Ranchi Clinical Lab', 'pincode' => '834001']);
        Branch::factory()->in($patna)->create(['branch_code' => 'PATPSC1', 'name' => 'Boring Road PSC', 'pincode' => '800001']);
        Branch::factory()->in($patna)->create(['branch_code' => 'PATPSC2', 'name' => 'Kankarbagh PSC', 'pincode' => '800020']);
        Branch::factory()->in($ranchi)->create(['branch_code' => 'RNCPSC1', 'name' => 'Lalpur PSC', 'pincode' => '834001']);

        $gaya = Franchise::factory()->in($bihar)->create(['franchise_code' => 'FRGAYA', 'name' => 'Gaya Diagnostics']);
        $dhanbad = Franchise::factory()->in($jharkhand)->create(['franchise_code' => 'FRDHN', 'name' => 'Dhanbad Health Point']);

        Branch::factory()->ownedBy($gaya)->create(['branch_code' => 'GAYPSC1', 'name' => 'Gaya Main PSC', 'pincode' => '823001']);
        Branch::factory()->ownedBy($gaya)->create(['branch_code' => 'GAYPSC2', 'name' => 'Bodh Gaya PSC', 'pincode' => '824231']);
        Branch::factory()->ownedBy($dhanbad)->create(['branch_code' => 'DHNPSC1', 'name' => 'Dhanbad Bank More PSC', 'pincode' => '826001']);
    }

    private function region(Organization $organization, string $name, ?Region $parent = null): Region
    {
        return Region::factory()->create([
            'organization_id' => $organization->id,
            'parent_region_id' => $parent?->id,
            'name' => $name,
            'region_type' => $parent === null ? RegionType::State : RegionType::City,
        ]);
    }
}
