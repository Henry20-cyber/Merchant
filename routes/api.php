<?php

use App\Domains\Identity\Controllers\AuthController;
use App\Domains\Identity\Controllers\RoleController;
use App\Domains\Organization\Controllers\BusinessContextController;
use App\Domains\Organization\Controllers\BusinessController;
use App\Domains\Organization\Controllers\BusinessMemberController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Public Authentication
|--------------------------------------------------------------------------
*/

Route::post('/auth/register', [
    AuthController::class,
    'register',
]);

Route::post('/auth/login', [
    AuthController::class,
    'login',
])->middleware('throttle:login');


/*
|--------------------------------------------------------------------------
| Authenticated User
|--------------------------------------------------------------------------
*/

Route::middleware([
    'auth:sanctum',
    'business.context',
])->get('/auth/me', function (Request $request) {
    return response()->json([
        'success' => true,
        'user' => [
            'id' => $request->user()->id,
            'name' => $request->user()->name,
            'email' => $request->user()->email,
        ],
    ]);
});


/*
|--------------------------------------------------------------------------
| Business Management
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Create Business
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->post('/businesses', [
    BusinessController::class,
    'store',
]);


/*
|--------------------------------------------------------------------------
| Current Business
|--------------------------------------------------------------------------
*/

Route::middleware([
    'auth:sanctum',
    'business.context',
])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | View Current Business
    |--------------------------------------------------------------------------
    */

    Route::get('/businesses/current', [
        BusinessController::class,
        'current',
    ])->middleware('permission:business.view');


    /*
    |--------------------------------------------------------------------------
    | Business Members
    |--------------------------------------------------------------------------
    */

    Route::get('/businesses/current/members', [
        BusinessMemberController::class,
        'index',
    ])->middleware('permission:users.view');


    /*
    |--------------------------------------------------------------------------
    | Assign Member Role
    |--------------------------------------------------------------------------
    */

    Route::put('/businesses/current/members/{user}/role', [
        BusinessMemberController::class,
        'assignRole',
    ])->middleware('permission:roles.assign');


    /*
    |--------------------------------------------------------------------------
    | Custom Role Management
    |--------------------------------------------------------------------------
    */

    Route::post('/businesses/current/roles', [
        RoleController::class,
        'store',
    ])->middleware('permission:roles.create');
});


/*
|--------------------------------------------------------------------------
| Specific Business
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | View Business
    |--------------------------------------------------------------------------
    */

    Route::get('/businesses/{business}', [
        BusinessController::class,
        'show',
    ]);


    /*
    |--------------------------------------------------------------------------
    | Update Business
    |--------------------------------------------------------------------------
    */

    Route::put('/businesses/{business}', [
        BusinessController::class,
        'update',
    ])->middleware([
        'business.context',
        'permission:business.update',
    ]);


    /*
    |--------------------------------------------------------------------------
    | Switch Business
    |--------------------------------------------------------------------------
    */

    Route::post('/businesses/{business}/switch', [
        BusinessContextController::class,
        'set',
    ]);
});


/*
|--------------------------------------------------------------------------
| Business Context
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/business/current', [
        BusinessContextController::class,
        'current',
    ]);

    Route::post('/business/current/clear', [
        BusinessContextController::class,
        'clear',
    ]);

    Route::get('/businesses/current/roles', [
        RoleController::class,
        'index',
    ])->middleware('permission:roles.view');

    Route::post('/businesses/current/roles', [
        RoleController::class,
        'store',
    ])->middleware('permission:roles.create');
});

Route::put('/businesses/current/roles/{role}', [
    RoleController::class,
    'update',
])->middleware('permission:roles.update');


/*
|--------------------------------------------------------------------------
| Development / Debugging
|--------------------------------------------------------------------------
|
| Remove this route before production.
|
*/

if (app()->environment('local')) {
    Route::middleware('auth:sanctum')->get(
        '/debug/auth-context',
        function (Request $request) {
            return response()->json([
                'user_id' => $request->user()->id,
                'session_business_id' => session('current_business_id'),
            ]);
        }
    );
}
