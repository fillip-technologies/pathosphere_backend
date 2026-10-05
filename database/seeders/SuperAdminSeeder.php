<?php

namespace Database\Seeders;

use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Auth\Services\StaffAccounts;
use App\Modules\Network\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The first Super Admin, from config('pathology.super_admin'). With no
 * password configured, a random one is generated and printed once.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(StaffAccounts $accounts): void
    {
        $settings = config('pathology.super_admin');

        if (User::query()->where('email', $settings['email'])->exists()) {
            return;
        }

        $user = new User([
            'role_id' => Role::query()->where('name', SystemRole::SuperAdmin->value)->whereNull('organization_id')->value('id'),
            'name' => $settings['name'],
            'email' => mb_strtolower($settings['email']),
            'phone' => $settings['phone'],
        ]);
        $user->organization_id = Organization::query()->value('id');
        $user->status = UserStatus::Active;
        $user->save();

        $password = $settings['password'] ?: Str::password(20);
        $accounts->create($user, $password);

        if (! $settings['password']) {
            $this->command?->warn("Super Admin {$user->email} created with password: {$password}");
            $this->command?->warn('Store it now; it is not shown again. MFA enrolment is required at first sign-in.');
        }
    }
}
