<?php

use App\Modules\Catalogue\Http\Controllers\DepartmentController;
use App\Modules\Catalogue\Http\Controllers\LabTestController;
use App\Modules\Catalogue\Http\Controllers\OrderQuoteController;
use App\Modules\Catalogue\Http\Controllers\PackageController;
use App\Modules\Catalogue\Http\Controllers\PriceListController;
use App\Modules\Catalogue\Http\Controllers\RoutingController;
use App\Modules\Catalogue\Http\Controllers\TestParameterController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'staff'])->group(function (): void {
    // The catalogue is readable by every staff member: booking screens need it.
    Route::get('/departments', [DepartmentController::class, 'index']);
    Route::get('/departments/{department}', [DepartmentController::class, 'show']);
    Route::get('/tests', [LabTestController::class, 'index']);
    Route::get('/tests/{test}', [LabTestController::class, 'show']);
    Route::get('/tests/{test}/parameters', [TestParameterController::class, 'index']);
    Route::get('/tests/{test}/parameters/{parameter}', [TestParameterController::class, 'show'])->scopeBindings();
    Route::get('/packages', [PackageController::class, 'index']);
    Route::get('/packages/{package}', [PackageController::class, 'show']);

    Route::middleware('permission:manage_catalog')->group(function (): void {
        Route::post('/departments', [DepartmentController::class, 'store']);
        Route::patch('/departments/{department}', [DepartmentController::class, 'update']);
        Route::delete('/departments/{department}', [DepartmentController::class, 'destroy']);

        Route::post('/tests', [LabTestController::class, 'store']);
        Route::patch('/tests/{test}', [LabTestController::class, 'update']);
        Route::delete('/tests/{test}', [LabTestController::class, 'destroy']);

        Route::scopeBindings()->group(function (): void {
            Route::post('/tests/{test}/parameters', [TestParameterController::class, 'store']);
            Route::patch('/tests/{test}/parameters/{parameter}', [TestParameterController::class, 'update']);
            Route::delete('/tests/{test}/parameters/{parameter}', [TestParameterController::class, 'destroy']);
        });

        Route::post('/packages', [PackageController::class, 'store']);
        Route::patch('/packages/{package}', [PackageController::class, 'update']);
        Route::delete('/packages/{package}', [PackageController::class, 'destroy']);
    });

    Route::middleware('permission:manage_price_lists')->group(function (): void {
        Route::get('/price-lists', [PriceListController::class, 'index']);
        Route::post('/price-lists', [PriceListController::class, 'store']);
        Route::get('/price-lists/{priceList}', [PriceListController::class, 'show']);
        Route::patch('/price-lists/{priceList}', [PriceListController::class, 'update']);
        Route::delete('/price-lists/{priceList}', [PriceListController::class, 'destroy']);
        Route::get('/price-lists/{priceList}/items', [PriceListController::class, 'items']);
        Route::put('/price-lists/{priceList}/items', [PriceListController::class, 'replaceItems']);
        Route::post('/price-lists/{priceList}/imports', [PriceListController::class, 'import']);
    });

    Route::middleware('permission:manage_routing')->group(function (): void {
        Route::get('/branches/{branchId}/capabilities', [RoutingController::class, 'capabilities'])->whereUuid('branchId');
        Route::put('/branches/{branchId}/capabilities', [RoutingController::class, 'replaceCapabilities'])->whereUuid('branchId');

        Route::get('/routing-rules', [RoutingController::class, 'index']);
        Route::post('/routing-rules', [RoutingController::class, 'store']);
        Route::get('/routing-rules/{routingRule}', [RoutingController::class, 'show']);
        Route::patch('/routing-rules/{routingRule}', [RoutingController::class, 'update']);
        Route::delete('/routing-rules/{routingRule}', [RoutingController::class, 'destroy']);
    });

    Route::get('/routing-resolutions', [RoutingController::class, 'resolve'])->middleware('permission:manage_routing,create_order');
    Route::post('/order-quotes', OrderQuoteController::class)->middleware('permission:create_order,create_b2b_order');
});
