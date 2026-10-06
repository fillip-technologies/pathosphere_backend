<?php

use App\Modules\Shared\Http\Controllers\AuditLogController;
use App\Modules\Shared\Http\Controllers\DeliveryWebhookController;
use App\Modules\Shared\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

// Delivery receipts from the SMS and WhatsApp vendors (spec §9 rule 5); signature-checked.
Route::post('/webhooks/sms-dlr', DeliveryWebhookController::class);
Route::post('/webhooks/whatsapp', DeliveryWebhookController::class);

Route::middleware(['auth:sanctum', 'staff', 'permission:view_audit'])->group(function (): void {
    Route::get('/audit-logs', [AuditLogController::class, 'index']);
});
