<?php

use App\Modules\Booking\Http\Controllers\AbdmCallbackController;
use App\Modules\Booking\Http\Controllers\AbhaController;
use App\Modules\Booking\Http\Controllers\DoctorController;
use App\Modules\Booking\Http\Controllers\HomeCollectionController;
use App\Modules\Booking\Http\Controllers\InvoiceController;
use App\Modules\Booking\Http\Controllers\OrderController;
use App\Modules\Booking\Http\Controllers\PatientController;
use App\Modules\Booking\Http\Controllers\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

// Gateway callbacks: no staff login; the signature is the authentication.
Route::post('/webhooks/razorpay', PaymentWebhookController::class);
Route::post('/abdm/callbacks/{callbackType}', AbdmCallbackController::class)->where('callbackType', '[a-z-]+');

Route::middleware(['auth:sanctum', 'staff'])->group(function (): void {
    Route::middleware('permission:register_patient,create_order')->group(function (): void {
        Route::get('/patients', [PatientController::class, 'index'])->middleware('throttle:60,1');
        Route::get('/patients/{patient}', [PatientController::class, 'show']);
        Route::get('/doctors', [DoctorController::class, 'index']);
        Route::get('/doctors/{doctor}', [DoctorController::class, 'show']);
    });

    Route::middleware('permission:register_patient')->group(function (): void {
        Route::post('/patients', [PatientController::class, 'store']);
        Route::patch('/patients/{patient}', [PatientController::class, 'update']);
        Route::post('/patients/{patient}/merge', [PatientController::class, 'merge']);
        Route::post('/doctors', [DoctorController::class, 'store']);
        Route::patch('/doctors/{doctor}', [DoctorController::class, 'update']);
        Route::delete('/doctors/{doctor}', [DoctorController::class, 'destroy']);
    });

    // ABHA milestone M1 (spec §5.7), renamed to nouns per decision D4.
    Route::middleware(['permission:register_patient', 'throttle:abha'])->group(function (): void {
        Route::post('/abha-verifications', [AbhaController::class, 'startVerification']);
        Route::post('/abha-verifications/{txnId}/confirmation', [AbhaController::class, 'confirmVerification']);
        Route::post('/abha-qr-scans', [AbhaController::class, 'scan']);
        Route::post('/abha-enrolments', [AbhaController::class, 'startEnrolment']);
        Route::post('/abha-enrolments/{txnId}/confirmation', [AbhaController::class, 'confirmEnrolment']);
        Route::post('/abha-enrolments/{txnId}/address', [AbhaController::class, 'chooseAddress']);
        Route::get('/patients/{patient}/abha-card', [AbhaController::class, 'card']);
        Route::delete('/patients/{patient}/abha-link', [AbhaController::class, 'unlink']);
        Route::get('/abha-profile-shares', [AbhaController::class, 'shareQueue']);
        Route::post('/abha-profile-shares/{shareId}/link', [AbhaController::class, 'linkShare'])->whereUuid('shareId');
    });

    Route::middleware('permission:create_order')->group(function (): void {
        Route::get('/orders', [OrderController::class, 'index']);
        Route::post('/orders', [OrderController::class, 'store'])->middleware('idempotent');
        Route::get('/orders/{order}', [OrderController::class, 'show']);
        Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
        Route::post('/orders/{order}/items', [OrderController::class, 'addItems'])->middleware('idempotent');
    });

    Route::middleware('permission:collect_payment,view_all_invoices')->group(function (): void {
        Route::get('/invoices', [InvoiceController::class, 'index']);
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
    });

    Route::middleware('permission:collect_payment')->group(function (): void {
        Route::post('/invoices/{invoice}/payments', [InvoiceController::class, 'pay'])->middleware('idempotent');
        Route::post('/payment-links', [InvoiceController::class, 'paymentLink']);
    });

    Route::post('/payments/{paymentId}/refunds', [InvoiceController::class, 'refund'])
        ->whereUuid('paymentId')
        ->middleware(['permission:approve_refund', 'idempotent']);

    Route::middleware('permission:manage_home_collection,view_assigned_collections')->group(function (): void {
        Route::get('/home-collections', [HomeCollectionController::class, 'index']);
        Route::get('/home-collections/{homeCollection}', [HomeCollectionController::class, 'show']);
    });
    Route::post('/home-collections/{homeCollection}/assign', [HomeCollectionController::class, 'assign'])
        ->middleware('permission:manage_home_collection');
    Route::post('/home-collections/{homeCollection}/status', [HomeCollectionController::class, 'updateStatus'])
        ->middleware('permission:manage_home_collection,mark_collected');
});
