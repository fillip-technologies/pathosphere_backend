<?php

namespace Database\Factories\Network;

use App\Modules\Network\Enums\FranchiseStatus;
use App\Modules\Network\Models\Franchise;
use App\Modules\Network\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Franchise> */
final class FranchiseFactory extends Factory
{
    protected $model = Franchise::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'franchise_code' => 'FR'.fake()->unique()->numerify('####'),
            'name' => fake()->company().' Diagnostics',
            'legal_name' => fake()->company().' Private Limited',
            'owner_name' => fake()->name(),
            'phone' => fake()->numerify('9#########'),
            'email' => fake()->unique()->safeEmail(),
            'pan' => fake()->regexify('[A-Z]{5}[0-9]{4}[A-Z]'),
            'address' => fake()->address(),
            'status' => FranchiseStatus::Active,
        ];
    }

    public function in(Region $region): self
    {
        return $this->state(['organization_id' => $region->organization_id, 'region_id' => $region->id]);
    }
}
