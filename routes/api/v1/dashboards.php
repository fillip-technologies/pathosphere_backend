<?php

use App\Modules\Dashboards\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

// Business dashboards from the nightly summary (spec §8, §11 observability 4).
Route::middleware(['auth:sanctum', 'staff'])->group(function (): void {
    Route::get('/dashboards/hq', [DashboardController::class, 'hq'])->middleware('permission:view_hq_dashboard');
    Route::get('/dashboards/region/{regionId}', [DashboardController::class, 'region'])->whereUuid('regionId')->middleware('permission:view_region_dashboard');
    Route::get('/dashboards/franchise/{franchiseId}', [DashboardController::class, 'franchise'])->whereUuid('franchiseId')->middleware('permission:view_franchise_dashboard');
    Route::get('/dashboards/branch/{branchId}', [DashboardController::class, 'branch'])->whereUuid('branchId')->middleware('permission:view_branch_dashboard');
});
