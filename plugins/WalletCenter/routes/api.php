<?php

use Illuminate\Support\Facades\Route;
use Plugin\WalletCenter\Controllers\AdminController;
use Plugin\WalletCenter\Controllers\AutoRenewController;
use Plugin\WalletCenter\Controllers\CheckinController;
use Plugin\WalletCenter\Controllers\TopupController;

Route::prefix('api/v1/wallet-center')->group(function (): void {
    Route::middleware('user')->group(function (): void {
        Route::prefix('checkin')->group(function (): void {
            Route::get('status', [CheckinController::class, 'status']);
            Route::post('claim', [CheckinController::class, 'claim']);
            Route::get('history', [CheckinController::class, 'history']);
        });

        Route::prefix('topup')->group(function (): void {
            Route::get('methods', [TopupController::class, 'methods']);
            Route::post('create', [TopupController::class, 'create']);
            Route::get('detail', [TopupController::class, 'detail']);
            Route::get('history', [TopupController::class, 'history']);
        });

        Route::prefix('auto-renew')->group(function (): void {
            Route::get('config', [AutoRenewController::class, 'config']);
            Route::post('config', [AutoRenewController::class, 'update']);
            Route::get('history', [AutoRenewController::class, 'history']);
        });
    });

    Route::match(['get', 'post'], 'topup/notify/{method}/{uuid}', [TopupController::class, 'notify']);

    Route::middleware(['admin', 'log'])->prefix('admin')->group(function (): void {
        Route::get('overview', [AdminController::class, 'overview']);
        Route::get('config', [AdminController::class, 'config']);
        Route::post('config', [AdminController::class, 'updateConfig']);
        Route::get('checkin/logs', [AdminController::class, 'checkinLogs']);
        Route::get('topup/orders', [AdminController::class, 'topupOrders']);
        Route::get('auto-renew/records', [AdminController::class, 'autoRenewRecords']);
    });
});
