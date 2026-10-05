<?php

namespace App\Modules\Catalogue;

use App\Modules\Catalogue\Models\RoutingRule;
use App\Modules\Network\Services\NetworkDirectory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class CatalogueServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Routing rules have no organization column; they are visible through
        // their source branch, which carries the caller's scope.
        Route::bind('routingRule', fn (string $id): RoutingRule => RoutingRule::query()
            ->whereIn('source_branch_id', app(NetworkDirectory::class)->visibleBranchIds())
            ->findOrFail($id));
    }
}
