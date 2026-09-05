<?php

use Illuminate\Support\Facades\Route;
use Plugin\MobileApp\Controllers\SkeletonController;

$registerVersion = static function (string $version): void {
    $namePrefix = 'mobile.v' . $version . '.';
    $controller = SkeletonController::class;

    Route::prefix('api/mobile/v' . $version)->group(function () use ($namePrefix, $controller): void {
        Route::get('bootstrap', [$controller, 'notImplemented'])->name($namePrefix . 'bootstrap.get');
        Route::get('legal/privacy', [$controller, 'notImplemented'])->name($namePrefix . 'legal.privacy.get');
        Route::get('legal/terms', [$controller, 'notImplemented'])->name($namePrefix . 'legal.terms.get');
        Route::get('legal/account-deletion', [$controller, 'notImplemented'])->name($namePrefix . 'legal.accountDeletion.get');
        Route::get('legal/support', [$controller, 'notImplemented'])->name($namePrefix . 'legal.support.get');
        Route::post('auth/register', [$controller, 'notImplemented'])->name($namePrefix . 'auth.register');
        Route::post('auth/login', [$controller, 'notImplemented'])->name($namePrefix . 'auth.login');
        Route::post('auth/email-code', [$controller, 'notImplemented'])->name($namePrefix . 'auth.emailCode');
        Route::post('auth/password-reset', [$controller, 'notImplemented'])->name($namePrefix . 'auth.passwordReset');

        Route::middleware('user')->group(function () use ($namePrefix, $controller): void {
            Route::get('auth/session', [$controller, 'notImplemented'])->name($namePrefix . 'auth.session');
            Route::post('auth/logout', [$controller, 'notImplemented'])->name($namePrefix . 'auth.logout');
            Route::get('account', [$controller, 'notImplemented'])->name($namePrefix . 'account.get');
            Route::get('entitlement', [$controller, 'notImplemented'])->name($namePrefix . 'entitlement.get');
            Route::get('plans', [$controller, 'notImplemented'])->name($namePrefix . 'plans.list');
            Route::get('nodes', [$controller, 'notImplemented'])->name($namePrefix . 'nodes.list');
            Route::get('profiles/{opaqueProfileId}', [$controller, 'notImplemented'])->name($namePrefix . 'profiles.get');
            Route::get('notices', [$controller, 'notImplemented'])->name($namePrefix . 'notices.list');
            Route::get('notices/{noticeId}', [$controller, 'notImplemented'])->name($namePrefix . 'notices.get');
            Route::post('notices/{noticeId}/read', [$controller, 'notImplemented'])->name($namePrefix . 'notices.read');
            Route::post('tickets', [$controller, 'notImplemented'])->name($namePrefix . 'tickets.create');
            Route::get('tickets', [$controller, 'notImplemented'])->name($namePrefix . 'tickets.list');
            Route::get('tickets/{ticketId}', [$controller, 'notImplemented'])->name($namePrefix . 'tickets.get');
            Route::post('tickets/{ticketId}/replies', [$controller, 'notImplemented'])->name($namePrefix . 'tickets.reply');
            Route::post('tickets/{ticketId}/close', [$controller, 'notImplemented'])->name($namePrefix . 'tickets.close');
            Route::put('devices', [$controller, 'notImplemented'])->name($namePrefix . 'devices.register');
            Route::post('play/purchases', [$controller, 'notImplemented'])->name($namePrefix . 'play.purchase.submit');
            Route::post('play/purchases/restore', [$controller, 'notImplemented'])->name($namePrefix . 'play.purchase.restore');
            Route::post('account/deletion/preview', [$controller, 'notImplemented'])->name($namePrefix . 'account.deletion.preview');
            Route::post('account/deletion', [$controller, 'notImplemented'])->name($namePrefix . 'account.deletion.submit');
            Route::get('account/deletion', [$controller, 'notImplemented'])->name($namePrefix . 'account.deletion.get');
        });

        Route::middleware(['admin', 'log'])->prefix('admin')->group(function () use ($namePrefix, $controller): void {
            Route::get('play-products', [$controller, 'notImplemented'])->name($namePrefix . 'admin.playProducts.list');
            Route::put('play-products', [$controller, 'notImplemented'])->name($namePrefix . 'admin.playProducts.upsert');
            Route::get('compat', [$controller, 'notImplemented'])->name($namePrefix . 'admin.compat.get');
            Route::put('compat', [$controller, 'notImplemented'])->name($namePrefix . 'admin.compat.update');
        });

        Route::post('platform/google/rtdn', [$controller, 'notImplemented'])
            ->middleware('mobile.google.rtdn')
            ->name($namePrefix . 'platform.google.rtdn');
    });
};

$registerVersion('0');
$registerVersion('1');
