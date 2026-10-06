<?php

use App\Modules\Auth\Http\Controllers\MeController;
use App\Modules\Auth\Http\Controllers\MfaController;
use App\Modules\Auth\Http\Controllers\PermissionController;
use App\Modules\Auth\Http\Controllers\RoleController;
use App\Modules\Auth\Http\Controllers\SignInController;
use App\Modules\Auth\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:sign-in')->prefix('auth')->group(function (): void {
    Route::post('/login', [SignInController::class, 'login']);
    Route::post('/mfa-verifications', [SignInController::class, 'completeMfa']);
    Route::post('/refresh', [SignInController::class, 'refresh']);
});

// Any signed-in account: staff, patient or doctor.
Route::post('/auth/logout', [SignInController::class, 'logout'])->middleware('auth:sanctum');

Route::middleware(['auth:sanctum', 'staff'])->group(function (): void {
    Route::get('/me', MeController::class);

    // MFA is optional: staff switch it on (start, then confirm a code) and off themselves.
    Route::middleware('throttle:10,1')->group(function (): void {
        Route::post('/me/mfa', [MfaController::class, 'start']);
        Route::post('/me/mfa/confirmation', [MfaController::class, 'confirm']);
        Route::post('/me/mfa/disable', [MfaController::class, 'disable']);
    });

    Route::middleware('permission:manage_staff,manage_franchise_staff,manage_branch_staff')->group(function (): void {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::patch('/users/{user}', [UserController::class, 'update']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
        Route::post('/users/{user}/disable', [UserController::class, 'disable']);
        Route::post('/users/{user}/enable', [UserController::class, 'enable']);
    });

    Route::middleware('permission:manage_roles')->group(function (): void {
        Route::get('/permissions', PermissionController::class);
        Route::get('/roles', [RoleController::class, 'index']);
        Route::post('/roles', [RoleController::class, 'store']);
        Route::get('/roles/{role}', [RoleController::class, 'show']);
        Route::patch('/roles/{role}', [RoleController::class, 'update']);
        Route::delete('/roles/{role}', [RoleController::class, 'destroy']);
        Route::put('/roles/{role}/permissions', [RoleController::class, 'replacePermissions']);
    });
});
