<?php

use Illuminate\Support\Facades\Route;
use App\Domains\Organization\Controllers\BusinessController;
use App\Domains\Organization\Controllers\BusinessTypeController;

Route::get('/business-types', [BusinessTypeController::class, 'index']);

Route::post(
    '/businesses',
    [BusinessController::class, 'store']
);