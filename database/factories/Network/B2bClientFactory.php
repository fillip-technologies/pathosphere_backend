<?php

namespace Database\Factories\Network;

use App\Modules\Network\Enums\B2bClientStatus;
use App\Modules\Network\Enums\B2bClientType;
use App\Modules\Network\Models\B2bClient;
use App\Modules\Network\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<B2bClient> */
final class B2bClientFactory extends Factory
{
    protected $model = B2bClient::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'client_type' => B2bClientType::Hospital,
            'client_code' => 'CL'.fake()->unique()->numerify('####'),
            'name' => fake()->company().' Hospital',
            'contact_name' => fake()->name(),
            'phone' => fake()->numerify('9#########'),
            'email' => fake()->unique()->safeEmail(),
            'billing_address' => fake()->address(),
            'status' => B2bClientStatus::Active,
        ];
    }

    public function servicedBy(Branch $branch): self
    {
        return $this->state([
            'organization_id' => $branch->organization_id,
            'region_id' => $branch->region_id,
            'serviced_by_branch_id' => $branch->id,
        ]);
    }
}
