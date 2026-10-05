<?php

use App\Modules\Shared\Http\Controllers\AuditLogController;
use App\Modules\Shared\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

Route::middleware(['auth:sanctum', 'staff', 'permission:view_audit'])->group(function (): void {
    Route::get('/audit-logs', [AuditLogController::class, 'index']);
});
