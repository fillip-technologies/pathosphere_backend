<?php

use App\Modules\Auth\Http\Controllers\MeController;
use App\Modules\Auth\Http\Controllers\PermissionController;
use App\Modules\Auth\Http\Controllers\RoleController;
use App\Modules\Auth\Http\Controllers\SignInController;
use App\Modules\Auth\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:sign-in')->prefix('auth')->group(function (): void {
    Route::post('/login', [SignInController::class, 'login']);
    Route::post('/mfa-enrollments', [SignInController::class, 'startMfaEnrollment']);
    Route::post('/mfa-verifications', [SignInController::class, 'completeMfa']);
    Route::post('/refresh', [SignInController::class, 'refresh']);
});

Route::middleware(['auth:sanctum', 'staff'])->group(function (): void {
    Route::post('/auth/logout', [SignInController::class, 'logout']);
    Route::get('/me', MeController::class);

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
