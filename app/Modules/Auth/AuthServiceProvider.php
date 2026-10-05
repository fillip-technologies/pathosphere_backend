<?php

namespace App\Modules\Auth;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Services\StaffContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(StaffContext::class);
    }

    public function boot(): void
    {
        // Login, refresh and MFA endpoints (spec §10.5): per IP, and per
        // identifier so one account cannot be brute-forced from many IPs.
        RateLimiter::for('sign-in', fn (Request $request): array => [
            Limit::perMinute(20)->by('ip:'.$request->ip()),
            Limit::perMinute(10)->by('login:'.mb_strtolower((string) $request->input('login_identifier'))),
        ]);

        // Roles are not network-scoped; limit them to the caller's organization.
        Route::bind('role', fn (string $id): Role => Role::query()
            ->availableTo(app(StaffContext::class)->user()->organization_id)
            ->with('permissionEntries')
            ->findOrFail($id));
    }
}
