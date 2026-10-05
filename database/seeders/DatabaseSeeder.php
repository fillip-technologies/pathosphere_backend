<?php

namespace Database\Seeders;

use App\Modules\Shared\Context\Actor;
use App\Modules\Shared\Context\CurrentActor;
use App\Modules\Shared\Scoping\CurrentScope;
use App\Modules\Shared\Scoping\ScopeContext;
use Illuminate\Database\Seeder;

/**
 * Seeds run as the system, with an explicit system scope (spec §4.3).
 *
 * Production: permissions, system roles, the organization and the first
 * Super Admin. Local/testing also gets the demo network of spec §11.6.
 */
class DatabaseSeeder extends Seeder
{
    public function run(CurrentScope $currentScope, CurrentActor $currentActor): void
    {
        $currentActor->runAs(Actor::system(), fn () => $currentScope->runAs(ScopeContext::system(), function (): void {
            $this->call([
                PermissionSeeder::class,
                OrganizationSeeder::class,
                SuperAdminSeeder::class,
                NotificationTemplateSeeder::class,
            ]);

            if (app()->environment('local', 'testing')) {
                $this->call([DevelopmentNetworkSeeder::class, DevelopmentCatalogueSeeder::class]);
            }
        }));
    }
}
