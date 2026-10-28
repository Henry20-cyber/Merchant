<?php

use App\Http\Controllers\Api\FounderDashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'founder'])
    ->prefix('founder')
    ->name('founder.')
    ->group(function (): void {
        Route::get('/overview', [FounderDashboardController::class, 'overview'])
            ->middleware('throttle:60,1')
            ->name('overview');
    });
