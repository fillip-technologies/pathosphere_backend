<?php

use App\Modules\Ledger\Http\Controllers\LedgerController;
use App\Modules\Ledger\Http\Controllers\SettlementController;
use App\Modules\Ledger\Http\Controllers\WalletTopupController;
use Illuminate\Support\Facades\Route;

/*
| Partner ledger, wallet and settlements (spec §5.6, §8). Partners read their
| own account through scope; branch staff never see the ledger.
*/
Route::middleware(['auth:sanctum', 'staff'])->group(function (): void {
    Route::middleware('permission:view_ledger,view_client_ledger,approve_settlement,post_ledger_adjustment')->group(function (): void {
        Route::get('/ledger', [LedgerController::class, 'index']);
        Route::get('/ledger/{ledgerEntry}', [LedgerController::class, 'show'])->whereUuid('ledgerEntry');
    });
    Route::post('/ledger/adjustments', [LedgerController::class, 'adjust'])
        ->middleware(['permission:post_ledger_adjustment', 'idempotent']);

    Route::middleware('permission:topup_wallet,view_ledger')->group(function (): void {
        Route::get('/wallet/topups', [WalletTopupController::class, 'index']);
        Route::get('/wallet/topups/{walletTopup}', [WalletTopupController::class, 'show']);
    });
    Route::post('/wallet/topups', [WalletTopupController::class, 'store'])
        ->middleware(['permission:topup_wallet', 'idempotent']);

    Route::middleware('permission:approve_settlement,view_ledger,view_client_ledger')->group(function (): void {
        Route::get('/settlements', [SettlementController::class, 'index']);
        Route::get('/settlements/{settlement}', [SettlementController::class, 'show']);
        Route::get('/settlements/{settlement}/statement', [SettlementController::class, 'statement']);
    });

    Route::middleware('permission:approve_settlement')->group(function (): void {
        Route::post('/settlements/{settlement}/approve', [SettlementController::class, 'approve']);
        Route::post('/settlements/{settlement}/dispute', [SettlementController::class, 'dispute']);
        Route::post('/settlements/{settlement}/mark-settled', [SettlementController::class, 'markSettled']);
    });
});
