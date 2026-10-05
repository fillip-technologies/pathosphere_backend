<?php

namespace Database\Factories\Network;

use App\Modules\Network\Enums\OrganizationStatus;
use App\Modules\Network\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Organization> */
final class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Pathology Network',
            'legal_name' => 'Pathology Network Private Limited',
            'hq_address' => fake()->address(),
            'settings' => [],
            'status' => OrganizationStatus::Active,
        ];
    }
}
