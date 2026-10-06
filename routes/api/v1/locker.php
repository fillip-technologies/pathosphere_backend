<?php

use App\Modules\Locker\Http\Controllers\AbdmConsentController;
use App\Modules\Locker\Http\Controllers\AbdmHipCallbackController;
use App\Modules\Locker\Http\Controllers\AccessLogController;
use App\Modules\Locker\Http\Controllers\DigiLockerController;
use App\Modules\Locker\Http\Controllers\DoctorRecordController;
use App\Modules\Locker\Http\Controllers\FamilyController;
use App\Modules\Locker\Http\Controllers\HealthProfileController;
use App\Modules\Locker\Http\Controllers\PersonSignInController;
use App\Modules\Locker\Http\Controllers\RecordController;
use App\Modules\Locker\Http\Controllers\ReminderController;
use App\Modules\Locker\Http\Controllers\ShareController;
use App\Modules\Locker\Http\Controllers\ShareLinkController;
use App\Modules\Locker\Http\Controllers\TrendController;
use Illuminate\Support\Facades\Route;

// Patient and doctor sign-in by SMS code (spec §8 Auth; nouns per decision D4).
Route::prefix('auth')->group(function (): void {
    Route::post('/otp-challenges', [PersonSignInController::class, 'challenge'])->middleware('throttle:otp-challenges');
    Route::post('/otp-verifications', [PersonSignInController::class, 'verify'])->middleware('throttle:otp-verifications');
});

// ABDM gateway calling our labs as Health Information Providers (spec §5.7 M2); verified by the gateway signature.
Route::post('/abdm/callbacks/hip/{callbackType}', AbdmHipCallbackController::class)
    ->where('callbackType', 'link-token|care-contexts-linked|discover|link-init|link-confirm|consent-notify|health-information-request');

// Public: a record opened through a share link (rate-limited, token is the proof).
Route::middleware('throttle:share-links')->group(function (): void {
    Route::get('/shared-records/{token}', [ShareLinkController::class, 'show'])->where('token', '[A-Za-z0-9]{48}');
    Route::get('/shared-records/{token}/file', [ShareLinkController::class, 'file'])->where('token', '[A-Za-z0-9]{48}');
});

// The patient app: the health locker (spec §8 Patient app). X-Patient-Id switches to a family member.
Route::middleware(['auth:sanctum', 'person:patient', 'patient-profile'])->prefix('me')->group(function (): void {
    Route::get('/reports', [RecordController::class, 'reports']);
    Route::get('/records', [RecordController::class, 'index']);
    Route::post('/records', [RecordController::class, 'store']);
    Route::get('/records/{recordId}', [RecordController::class, 'show'])->whereUuid('recordId');
    Route::get('/records/{recordId}/file', [RecordController::class, 'file'])->whereUuid('recordId');
    Route::post('/records/{recordId}/documents', [RecordController::class, 'addVersion'])->whereUuid('recordId');

    Route::get('/trends', [TrendController::class, 'index']);
    Route::get('/trends/{parameterCode}', [TrendController::class, 'show'])->where('parameterCode', '[A-Za-z0-9_-]{1,30}');

    Route::get('/shares', [ShareController::class, 'index']);
    Route::post('/shares', [ShareController::class, 'store']);
    Route::get('/shares/{shareId}', [ShareController::class, 'show'])->whereUuid('shareId');
    Route::delete('/shares/{shareId}', [ShareController::class, 'destroy'])->whereUuid('shareId');

    Route::get('/family', [FamilyController::class, 'show']);
    Route::post('/family', [FamilyController::class, 'store']);
    Route::get('/family/{memberId}', [FamilyController::class, 'showMember'])->whereUuid('memberId');
    Route::patch('/family/{memberId}', [FamilyController::class, 'update'])->whereUuid('memberId');
    Route::delete('/family/{memberId}', [FamilyController::class, 'destroy'])->whereUuid('memberId');

    Route::get('/health-profile', [HealthProfileController::class, 'show']);
    Route::put('/health-profile', [HealthProfileController::class, 'update']);

    Route::get('/reminders', [ReminderController::class, 'index']);
    Route::post('/reminders', [ReminderController::class, 'store']);
    Route::get('/reminders/{reminderId}', [ReminderController::class, 'show'])->whereUuid('reminderId');
    Route::post('/reminders/{reminderId}/dismiss', [ReminderController::class, 'dismiss'])->whereUuid('reminderId');

    Route::get('/record-access-logs', [AccessLogController::class, 'index']);

    // What the patient allowed other health systems to receive through ABDM (granted in their ABHA app).
    Route::get('/abdm-consents', [AbdmConsentController::class, 'index']);

    // DigiLocker pull (spec §3, Phase 9): the patient signs in at DigiLocker and picks documents to keep.
    Route::middleware('throttle:digilocker')->group(function (): void {
        Route::post('/digilocker-sessions', [DigiLockerController::class, 'store']);
        Route::get('/digilocker-sessions/{sessionId}', [DigiLockerController::class, 'show'])->whereUuid('sessionId');
        Route::post('/digilocker-sessions/{sessionId}/authorization', [DigiLockerController::class, 'authorize'])->whereUuid('sessionId');
        Route::get('/digilocker-sessions/{sessionId}/documents', [DigiLockerController::class, 'documents'])->whereUuid('sessionId');
        Route::post('/digilocker-sessions/{sessionId}/imports', [DigiLockerController::class, 'import'])->whereUuid('sessionId');
    });
});

// The referring doctor's app: records shared with them by consent.
Route::middleware(['auth:sanctum', 'person:doctor', 'doctor-context'])->prefix('me')->group(function (): void {
    Route::get('/shared-records', [DoctorRecordController::class, 'index']);
    Route::get('/shared-records/{recordId}', [DoctorRecordController::class, 'show'])->whereUuid('recordId');
    Route::get('/shared-records/{recordId}/file', [DoctorRecordController::class, 'file'])->whereUuid('recordId');
});
