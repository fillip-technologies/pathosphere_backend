<?php

use App\Modules\Network\Http\Controllers\AgreementController;
use App\Modules\Network\Http\Controllers\B2bClientController;
use App\Modules\Network\Http\Controllers\BranchController;
use App\Modules\Network\Http\Controllers\ESignWebhookController;
use App\Modules\Network\Http\Controllers\FranchiseController;
use App\Modules\Network\Http\Controllers\FranchiseDocumentController;
use App\Modules\Network\Http\Controllers\OrganizationController;
use App\Modules\Network\Http\Controllers\RegionController;
use Illuminate\Support\Facades\Route;

// E-sign vendor callback: no staff login; the signature is the authentication.
Route::post('/webhooks/esign', ESignWebhookController::class);

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

    // Franchise onboarding (spec §5.1). Franchise owners see their own record and papers.
    Route::middleware('permission:onboard_franchise,verify_kyc,approve_agreement,suspend_franchise,view_franchise_dashboard')->group(function (): void {
        Route::get('/franchises', [FranchiseController::class, 'index']);
        Route::get('/franchises/{franchise}', [FranchiseController::class, 'show']);
    });

    Route::middleware('permission:onboard_franchise')->group(function (): void {
        Route::post('/franchises', [FranchiseController::class, 'store']);
        Route::patch('/franchises/{franchise}', [FranchiseController::class, 'update']);
        Route::delete('/franchises/{franchise}', [FranchiseController::class, 'destroy']);
    });

    Route::middleware('permission:suspend_franchise')->group(function (): void {
        Route::post('/franchises/{franchise}/suspend', [FranchiseController::class, 'suspend']);
        Route::post('/franchises/{franchise}/activate', [FranchiseController::class, 'activate']);
    });

    // Termination is the Super Admin's call (spec §5.1).
    Route::middleware('permission:manage_organization')->group(function (): void {
        Route::post('/franchises/{franchise}/terminate', [FranchiseController::class, 'terminate']);
        Route::post('/agreements/{agreement}/terminate', [AgreementController::class, 'terminate']);
    });

    Route::middleware('permission:onboard_franchise,verify_kyc,view_franchise_dashboard')->group(function (): void {
        Route::get('/franchises/{franchise}/documents', [FranchiseDocumentController::class, 'index']);
        Route::post('/franchises/{franchise}/documents', [FranchiseDocumentController::class, 'store']);
        Route::get('/franchise-documents/{franchiseDocument}', [FranchiseDocumentController::class, 'show']);
        Route::get('/franchise-documents/{franchiseDocument}/file', [FranchiseDocumentController::class, 'file']);
    });

    Route::post('/franchise-documents/{franchiseDocument}/verify', [FranchiseDocumentController::class, 'verify'])
        ->middleware('permission:verify_kyc');

    Route::middleware('permission:onboard_franchise,approve_agreement,view_franchise_dashboard')->group(function (): void {
        Route::get('/franchises/{franchise}/agreements', [AgreementController::class, 'index']);
        Route::get('/agreements/{agreement}', [AgreementController::class, 'show']);
        Route::get('/agreements/{agreement}/document', [AgreementController::class, 'document']);
    });

    Route::middleware('permission:approve_agreement')->group(function (): void {
        Route::post('/franchises/{franchise}/agreements', [AgreementController::class, 'store']);
        Route::patch('/agreements/{agreement}', [AgreementController::class, 'update']);
        Route::delete('/agreements/{agreement}', [AgreementController::class, 'destroy']);
        Route::post('/agreements/{agreement}/send-for-sign', [AgreementController::class, 'sendForSign']);
    });

    // B2B clients; the servicing branch's desk and the client's own users read them too.
    Route::middleware('permission:manage_b2b,create_order,view_client_ledger')->group(function (): void {
        Route::get('/b2b-clients', [B2bClientController::class, 'index']);
        Route::get('/b2b-clients/{b2bClient}', [B2bClientController::class, 'show']);
    });

    Route::middleware('permission:manage_b2b')->group(function (): void {
        Route::post('/b2b-clients', [B2bClientController::class, 'store']);
        Route::patch('/b2b-clients/{b2bClient}', [B2bClientController::class, 'update']);
        Route::post('/b2b-clients/{b2bClient}/hold', [B2bClientController::class, 'hold']);
        Route::post('/b2b-clients/{b2bClient}/activate', [B2bClientController::class, 'activate']);
        Route::post('/b2b-clients/{b2bClient}/close', [B2bClientController::class, 'close']);
    });
});
