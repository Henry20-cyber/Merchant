<?php

use Illuminate\Support\Facades\Route;
use App\Domains\Organization\Controllers\BusinessContextController;

Route::inertia('/', 'welcome')->name('home');

Route::middleware('auth')->group(function () {
    Route::post(
        '/businesses/{business}/switch',
        [BusinessContextController::class, 'set']
    );

    Route::get(
        '/business/current',
        [BusinessContextController::class, 'current']
    );

    Route::post(
        '/business/current/clear',
        [BusinessContextController::class, 'clear']
    );
});
