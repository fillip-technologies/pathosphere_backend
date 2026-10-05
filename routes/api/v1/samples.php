<?php

use App\Modules\Samples\Http\Controllers\ManifestController;
use App\Modules\Samples\Http\Controllers\SampleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'staff'])->group(function (): void {
    Route::middleware('permission:print_barcode,mark_collected,create_manifest,receive_manifest,accession_sample,reject_sample')->group(function (): void {
        Route::get('/samples', [SampleController::class, 'index']);
        Route::get('/samples/{barcodeOrId}', [SampleController::class, 'show'])->where('barcodeOrId', '[A-Za-z0-9-]{4,36}');
    });

    Route::post('/orders/{orderId}/samples', [SampleController::class, 'prepareForOrder'])
        ->whereUuid('orderId')
        ->middleware('permission:print_barcode');
    Route::post('/samples/{sample}/assign-barcode', [SampleController::class, 'assignBarcode'])
        ->middleware('permission:print_barcode,mark_collected');
    Route::post('/samples/{sample}/collect', [SampleController::class, 'collect'])
        ->middleware('permission:mark_collected');
    Route::post('/samples/{sample}/receive', [SampleController::class, 'receive'])
        ->middleware('permission:accession_sample');
    Route::post('/samples/{sample}/reject', [SampleController::class, 'reject'])
        ->middleware('permission:reject_sample');
    Route::post('/samples/{sample}/reroute', [SampleController::class, 'reroute'])
        ->middleware('permission:accession_sample');

    Route::middleware('permission:create_manifest,receive_manifest,accession_sample')->group(function (): void {
        Route::get('/manifests', [ManifestController::class, 'index']);
        Route::get('/manifests/{manifest}', [ManifestController::class, 'show']);
    });

    Route::middleware('permission:create_manifest')->group(function (): void {
        Route::post('/manifests', [ManifestController::class, 'store']);
        Route::post('/manifests/{manifest}/samples', [ManifestController::class, 'addSamples']);
        Route::delete('/manifests/{manifest}/samples/{sampleId}', [ManifestController::class, 'removeSample'])->whereUuid('sampleId');
        Route::post('/manifests/{manifest}/dispatch', [ManifestController::class, 'dispatch']);
    });

    // The receiving lab scans the bag in: its runner or its accessioning technician.
    Route::post('/manifests/{manifest}/receive', [ManifestController::class, 'receive'])
        ->middleware('permission:receive_manifest,accession_sample');
});
