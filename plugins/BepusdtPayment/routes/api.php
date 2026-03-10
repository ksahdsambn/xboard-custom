<?php

use Illuminate\Support\Facades\Route;
use Plugin\BepusdtPayment\Controllers\AdminController;

Route::prefix('api/v1/bepusdt-payment')->group(function (): void {
    Route::middleware(['admin', 'log'])->prefix('admin')->group(function (): void {
        Route::get('overview', [AdminController::class, 'overview']);
    });
});
