<?php

use App\Http\Controllers\Api\FounderDashboardController;
use App\Http\Controllers\Api\FounderPaymentRecoveryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'founder'])
    ->prefix('founder')
    ->name('founder.')
    ->group(function (): void {
        Route::get('/overview', [FounderDashboardController::class, 'overview'])
            ->middleware('throttle:60,1')
            ->name('overview');

        Route::get('/payment-recoveries', [FounderPaymentRecoveryController::class, 'index'])
            ->middleware('throttle:60,1')
            ->name('payment-recoveries.index');

        Route::post('/payment-recoveries/{recoveryId}/resolve', [FounderPaymentRecoveryController::class, 'resolve'])
            ->middleware('throttle:30,1')
            ->name('payment-recoveries.resolve');
    });
