<?php

namespace Database\Factories\Auth;

use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Auth\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->numerify('9#########'),
            'status' => UserStatus::Active,
        ];
    }
}
