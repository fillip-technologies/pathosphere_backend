<?php

namespace Database\Factories\Network;

use App\Modules\Network\Enums\RegionType;
use App\Modules\Network\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Region> */
final class RegionFactory extends Factory
{
    protected $model = Region::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city(),
            'region_type' => RegionType::State,
        ];
    }

    public function under(Region $parent): self
    {
        return $this->state([
            'organization_id' => $parent->organization_id,
            'parent_region_id' => $parent->id,
            'region_type' => RegionType::City,
        ]);
    }
}
