<?php

use Illuminate\Support\Facades\Route;
use Plugin\MobileApp\Controllers\AccountController;
use Plugin\MobileApp\Controllers\AdminCompatController;
use Plugin\MobileApp\Controllers\AuthController;
use Plugin\MobileApp\Controllers\BootstrapController;
use Plugin\MobileApp\Controllers\LegalController;
use Plugin\MobileApp\Controllers\NodeController;
use Plugin\MobileApp\Controllers\PlanController;
use Plugin\MobileApp\Controllers\ProfileController;
use Plugin\MobileApp\Controllers\SkeletonController;

$registerVersion = static function (string $version): void {
    $namePrefix = 'mobile.v' . $version . '.';
    $controller = SkeletonController::class;

    Route::prefix('api/mobile/v' . $version)->middleware('mobile.envelope')->group(function () use ($namePrefix, $controller): void {
        Route::get('bootstrap', [BootstrapController::class, 'show'])->name($namePrefix . 'bootstrap.get');
        Route::get('legal/privacy', [LegalController::class, 'privacy'])->name($namePrefix . 'legal.privacy.get');
        Route::get('legal/terms', [LegalController::class, 'terms'])->name($namePrefix . 'legal.terms.get');
        Route::get('legal/account-deletion', [LegalController::class, 'accountDeletion'])->name($namePrefix . 'legal.accountDeletion.get');
        Route::get('legal/support', [LegalController::class, 'support'])->name($namePrefix . 'legal.support.get');
        Route::post('auth/register', [AuthController::class, 'register'])->name($namePrefix . 'auth.register');
        Route::post('auth/login', [AuthController::class, 'login'])->name($namePrefix . 'auth.login');
        Route::post('auth/email-code', [AuthController::class, 'emailCode'])->name($namePrefix . 'auth.emailCode');
        Route::post('auth/password-reset', [AuthController::class, 'passwordReset'])->name($namePrefix . 'auth.passwordReset');

        Route::middleware('user')->group(function () use ($namePrefix, $controller): void {
            Route::get('auth/session', [AuthController::class, 'session'])->name($namePrefix . 'auth.session');
            Route::post('auth/logout', [AuthController::class, 'logout'])->name($namePrefix . 'auth.logout');
            Route::get('account', [AccountController::class, 'show'])->name($namePrefix . 'account.get');
            Route::get('entitlement', [AccountController::class, 'entitlement'])->name($namePrefix . 'entitlement.get');
            Route::get('plans', [PlanController::class, 'index'])->name($namePrefix . 'plans.list');
            Route::get('nodes', [NodeController::class, 'index'])->name($namePrefix . 'nodes.list');
            Route::middleware('mobile.startup:connect')->group(function () use ($namePrefix, $controller): void {
                Route::get('profiles/{opaqueProfileId}', [ProfileController::class, 'show'])->name($namePrefix . 'profiles.get');
            });
            Route::get('notices', [$controller, 'notImplemented'])->name($namePrefix . 'notices.list');
            Route::get('notices/{noticeId}', [$controller, 'notImplemented'])->name($namePrefix . 'notices.get');
            Route::post('notices/{noticeId}/read', [$controller, 'notImplemented'])->name($namePrefix . 'notices.read');
            Route::post('tickets', [$controller, 'notImplemented'])->name($namePrefix . 'tickets.create');
            Route::get('tickets', [$controller, 'notImplemented'])->name($namePrefix . 'tickets.list');
            Route::get('tickets/{ticketId}', [$controller, 'notImplemented'])->name($namePrefix . 'tickets.get');
            Route::post('tickets/{ticketId}/replies', [$controller, 'notImplemented'])->name($namePrefix . 'tickets.reply');
            Route::post('tickets/{ticketId}/close', [$controller, 'notImplemented'])->name($namePrefix . 'tickets.close');
            Route::put('devices', [$controller, 'notImplemented'])->name($namePrefix . 'devices.register');
            Route::middleware('mobile.startup:purchase')->group(function () use ($namePrefix, $controller): void {
                Route::post('play/purchases', [$controller, 'notImplemented'])->name($namePrefix . 'play.purchase.submit');
                Route::post('play/purchases/restore', [$controller, 'notImplemented'])->name($namePrefix . 'play.purchase.restore');
            });
            Route::post('account/deletion/preview', [$controller, 'notImplemented'])->name($namePrefix . 'account.deletion.preview');
            Route::post('account/deletion', [$controller, 'notImplemented'])->name($namePrefix . 'account.deletion.submit');
            Route::get('account/deletion', [$controller, 'notImplemented'])->name($namePrefix . 'account.deletion.get');
        });

        Route::middleware(['admin', 'log'])->prefix('admin')->group(function () use ($namePrefix, $controller): void {
            Route::get('play-products', [$controller, 'notImplemented'])->name($namePrefix . 'admin.playProducts.list');
            Route::put('play-products', [$controller, 'notImplemented'])->name($namePrefix . 'admin.playProducts.upsert');
            Route::get('compat', [AdminCompatController::class, 'show'])->name($namePrefix . 'admin.compat.get');
            Route::put('compat', [AdminCompatController::class, 'update'])->name($namePrefix . 'admin.compat.update');
        });

        Route::post('platform/google/rtdn', [$controller, 'notImplemented'])
            ->middleware('mobile.google.rtdn')
            ->name($namePrefix . 'platform.google.rtdn');
    });
};

$registerVersion('0');
$registerVersion('1');
