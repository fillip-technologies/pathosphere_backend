<?php

namespace Database\Factories\Network;

use App\Modules\Network\Enums\BranchOwnerType;
use App\Modules\Network\Enums\BranchStatus;
use App\Modules\Network\Enums\BranchType;
use App\Modules\Network\Models\Branch;
use App\Modules\Network\Models\Franchise;
use App\Modules\Network\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Branch> */
final class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'owner_type' => BranchOwnerType::Company,
            'franchise_id' => null,
            'branch_code' => 'BR'.fake()->unique()->numerify('####'),
            'name' => fake()->city().' Collection Centre',
            'branch_type' => BranchType::Psc,
            'address' => fake()->address(),
            'pincode' => fake()->numerify('8#####'),
            'phone' => fake()->numerify('9#########'),
            'status' => BranchStatus::Active,
        ];
    }

    public function in(Region $region): self
    {
        return $this->state(['organization_id' => $region->organization_id, 'region_id' => $region->id]);
    }

    public function ownedBy(Franchise $franchise): self
    {
        return $this->state([
            'organization_id' => $franchise->organization_id,
            'region_id' => $franchise->region_id,
            'owner_type' => BranchOwnerType::Franchise,
            'franchise_id' => $franchise->id,
        ]);
    }

    public function ofType(BranchType $type): self
    {
        return $this->state(['branch_type' => $type]);
    }
}
