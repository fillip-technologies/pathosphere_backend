<?php

use App\Modules\Network\Http\Controllers\BranchController;
use App\Modules\Network\Http\Controllers\OrganizationController;
use App\Modules\Network\Http\Controllers\RegionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'staff'])->group(function (): void {
    Route::middleware('permission:manage_organization')->group(function (): void {
        Route::get('/organization', [OrganizationController::class, 'show']);
        Route::patch('/organization', [OrganizationController::class, 'update']);
    });

    Route::middleware('permission:manage_branches,view_branches')->group(function (): void {
        Route::get('/regions', [RegionController::class, 'index']);
        Route::get('/regions/{region}', [RegionController::class, 'show']);
        Route::get('/branches', [BranchController::class, 'index']);
        Route::get('/branches/{branch}', [BranchController::class, 'show']);
    });

    Route::middleware('permission:manage_branches')->group(function (): void {
        Route::post('/regions', [RegionController::class, 'store']);
        Route::patch('/regions/{region}', [RegionController::class, 'update']);
        Route::delete('/regions/{region}', [RegionController::class, 'destroy']);

        Route::post('/branches', [BranchController::class, 'store']);
        Route::patch('/branches/{branch}', [BranchController::class, 'update']);
        Route::delete('/branches/{branch}', [BranchController::class, 'destroy']);
        Route::post('/branches/{branch}/activate', [BranchController::class, 'activate']);
        Route::post('/branches/{branch}/suspend', [BranchController::class, 'suspend']);
        Route::post('/branches/{branch}/close', [BranchController::class, 'close']);
    });
});
