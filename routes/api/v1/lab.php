<?php

use App\Modules\Lab\Http\Controllers\AgentController;
use App\Modules\Lab\Http\Controllers\InterfaceAgentController;
use App\Modules\Lab\Http\Controllers\PublicReportController;
use App\Modules\Lab\Http\Controllers\ReportController;
use App\Modules\Lab\Http\Controllers\ResultController;
use App\Modules\Lab\Http\Controllers\SignatoryController;
use App\Modules\Lab\Http\Controllers\WorklistController;
use Illuminate\Support\Facades\Route;

// Public: the QR verification page and signed report links (spec §8 Public, §9 rule 2).
Route::get('/verify/{qrCode}', [PublicReportController::class, 'verify'])
    ->where('qrCode', '[a-f0-9]{16,64}')
    ->middleware('throttle:report-verify');
Route::get('/report-links/{reportId}', [PublicReportController::class, 'download'])
    ->whereUuid('reportId')
    ->middleware('signed')
    ->name('report-links.show');

// The lab's interface agent: per-lab API key and IP allow-list (spec §8.1).
Route::middleware('interface-agent')->prefix('agent')->group(function (): void {
    Route::get('/orders', [AgentController::class, 'orders']);
    Route::post('/results', [AgentController::class, 'results']);
});

Route::middleware(['auth:sanctum', 'staff'])->group(function (): void {
    Route::middleware('permission:manage_signatories')->group(function (): void {
        Route::get('/signatories', [SignatoryController::class, 'index']);
        Route::post('/signatories', [SignatoryController::class, 'store']);
        Route::get('/signatories/{signatory}', [SignatoryController::class, 'show']);
        Route::patch('/signatories/{signatory}', [SignatoryController::class, 'update']);
        Route::delete('/signatories/{signatory}', [SignatoryController::class, 'destroy']);
    });

    Route::middleware('permission:manage_branches')->group(function (): void {
        Route::get('/interface-agents', [InterfaceAgentController::class, 'index']);
        Route::post('/interface-agents', [InterfaceAgentController::class, 'store']);
        Route::post('/interface-agents/{interfaceAgent}/revoke', [InterfaceAgentController::class, 'revoke']);
    });

    Route::middleware('permission:enter_results,verify_results,rerun_test')->group(function (): void {
        Route::get('/worklist', [WorklistController::class, 'index']);
        Route::get('/order-items/{orderItemId}/results', [WorklistController::class, 'results'])->whereUuid('orderItemId');
    });

    Route::post('/results', [ResultController::class, 'store'])->middleware('permission:enter_results');
    Route::post('/results/{result}/verify', [ResultController::class, 'verify'])->middleware('permission:verify_results');
    Route::post('/order-items/{orderItemId}/verify', [ResultController::class, 'verifyTest'])->whereUuid('orderItemId')->middleware('permission:verify_results');
    Route::post('/order-items/{orderItemId}/rerun', [ResultController::class, 'rerun'])->whereUuid('orderItemId')->middleware('permission:rerun_test');

    Route::middleware('permission:view_all_reports,view_reports,view_branch_reports,view_client_reports,sign_report,release_report,amend_report,verify_results')->group(function (): void {
        Route::get('/reports', [ReportController::class, 'index']);
        Route::get('/reports/{report}', [ReportController::class, 'show']);
        Route::get('/reports/{report}/pdf', [ReportController::class, 'pdf']);
    });

    Route::post('/reports/{report}/sign', [ReportController::class, 'sign'])->middleware('permission:sign_report');
    Route::post('/reports/{report}/release', [ReportController::class, 'release'])->middleware('permission:release_report');
    Route::post('/reports/{report}/amend', [ReportController::class, 'amend'])->middleware('permission:amend_report');
});
