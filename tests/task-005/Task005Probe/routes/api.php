<?php
use Illuminate\Support\Facades\Route;

Route::get('/task005-unprefixed', fn () => response()->json(['probe' => true]))->name('task005.unprefixed');
Route::prefix('/api/mobile/v1/task005-probe')->group(function () {
    Route::get('/public', fn () => response()->json(['probe' => true]))->name('task005.public');
    Route::middleware('user')->get('/protected', fn () => response()->json(['probe' => true]))->name('task005.protected');
});
