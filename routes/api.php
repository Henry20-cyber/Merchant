<?php

use App\Domains\Identity\Controllers\AuthController;
use App\Domains\Identity\Controllers\RoleController;
use App\Domains\Product\Controllers\ProductController;
use App\Domains\Inventory\Controllers\InventoryController;
use App\Domains\Organization\Controllers\BusinessContextController;
use App\Domains\Organization\Controllers\BusinessController;
use App\Domains\Organization\Controllers\BusinessMemberController;
use App\Domains\Subscription\Controllers\SubscriptionController;
use App\Domains\Customer\Controllers\CustomerController;
use App\Domains\Sales\Http\Controllers\SaleController;
use App\Domains\Payment\Controllers\PaystackWebhookController;
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
    | Business Context
    |--------------------------------------------------------------------------
    */

    Route::get('/business/current', [
        BusinessContextController::class,
        'current',
    ]);

    Route::post('/business/current/clear', [
        BusinessContextController::class,
        'clear',
    ]);


    /*
    |--------------------------------------------------------------------------
    | Custom Role Management
    |--------------------------------------------------------------------------
    */

    Route::get('/businesses/current/roles', [
        RoleController::class,
        'index',
    ])->middleware('permission:roles.view');

    Route::post('/businesses/current/roles', [
        RoleController::class,
        'store',
    ])->middleware('permission:roles.create');

    Route::put('/businesses/current/roles/{role}', [
        RoleController::class,
        'update',
    ])->middleware('permission:roles.update');

    Route::delete('/businesses/current/roles/{role}', [
        RoleController::class,
        'destroy',
    ])->middleware('permission:roles.delete');


    /*
    |--------------------------------------------------------------------------
    | Product Management
    |--------------------------------------------------------------------------
    */


    /*
    |--------------------------------------------------------------------------
    | Products
    |--------------------------------------------------------------------------
    */

    Route::get('/businesses/current/products', [
        ProductController::class,
        'index',
    ])->middleware('permission:products.view');

    Route::post('/businesses/current/products', [
        ProductController::class,
        'store',
    ])->middleware('permission:products.create');

    Route::get('/businesses/current/products/{product}', [
        ProductController::class,
        'show',
    ])->middleware('permission:products.view');

    Route::put('/businesses/current/products/{product}', [
        ProductController::class,
        'update',
    ])->middleware('permission:products.update');

    Route::delete('/businesses/current/products/{product}', [
        ProductController::class,
        'destroy',
    ])->middleware('permission:products.delete');


    /*
    |--------------------------------------------------------------------------
    | Product Units
    |--------------------------------------------------------------------------
    |
    | Product units are managed through ProductController.
    |
    */

    Route::post(
        '/businesses/current/products/{product}/units',
        [
            ProductController::class,
            'storeUnit',
        ]
    )->middleware('permission:products.update');

    Route::put(
        '/businesses/current/products/{product}/units/{unit}',
        [
            ProductController::class,
            'updateUnit',
        ]
    )->middleware('permission:products.update');

    Route::post(
        '/businesses/current/products/{product}/units/{unit}/base',
        [
            ProductController::class,
            'setBaseUnit',
        ]
    )->middleware('permission:products.update');

    Route::delete(
        '/businesses/current/products/{product}/units/{unit}',
        [
            ProductController::class,
            'destroyUnit',
        ]
    )->middleware('permission:products.update');
});


/*
|--------------------------------------------------------------------------
| Customer Management
|--------------------------------------------------------------------------
*/

Route::middleware([
    'auth:sanctum',
    'business.context',
])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Customers
    |--------------------------------------------------------------------------
    */

    Route::get('/businesses/current/customers', [
        CustomerController::class,
        'index',
    ])->middleware('permission:customers.view');

    Route::post('/businesses/current/customers', [
        CustomerController::class,
        'store',
    ])->middleware('permission:customers.create');

    Route::get('/businesses/current/customers/{customer}', [
        CustomerController::class,
        'show',
    ])->middleware('permission:customers.view');

    Route::put('/businesses/current/customers/{customer}', [
        CustomerController::class,
        'update',
    ])->middleware('permission:customers.update');

    Route::delete('/businesses/current/customers/{customer}', [
        CustomerController::class,
        'destroy',
    ])->middleware('permission:customers.delete');
});


/*
|--------------------------------------------------------------------------
| Inventory Management
|--------------------------------------------------------------------------
*/

Route::middleware([
    'auth:sanctum',
    'business.context',
])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Inventory
    |--------------------------------------------------------------------------
    */

    Route::get('/businesses/current/inventory', [
        InventoryController::class,
        'index',
    ])->middleware('permission:inventory.view');

    Route::get('/businesses/current/inventory/{stock}', [
        InventoryController::class,
        'show',
    ])->middleware('permission:inventory.view');


    /*
    |--------------------------------------------------------------------------
    | Receive Stock
    |--------------------------------------------------------------------------
    */

    Route::post('/businesses/current/inventory/receive', [
        InventoryController::class,
        'receive',
    ])->middleware('permission:inventory.receive');


    /*
    |--------------------------------------------------------------------------
    | Adjust Stock
    |--------------------------------------------------------------------------
    */

    Route::post('/businesses/current/inventory/adjust', [
        InventoryController::class,
        'adjust',
    ])->middleware('permission:inventory.adjust');


    /*
    |--------------------------------------------------------------------------
    | Movement History
    |--------------------------------------------------------------------------
    */

    Route::get('/businesses/current/inventory/{stock}/movements', [
        InventoryController::class,
        'movements',
    ])->middleware('permission:inventory.view');
});


/*
|--------------------------------------------------------------------------
| Sales
|--------------------------------------------------------------------------
*/

Route::middleware([
    'auth:sanctum',
    'business.context',
])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Sales Dashboard
    |--------------------------------------------------------------------------
    |
    | Read-only sales analytics.
    |
    */

    Route::get('/businesses/current/sales/dashboard', [
        SaleController::class,
        'dashboard',
    ])->middleware('permission:sales.view');


    /*
    |--------------------------------------------------------------------------
    | Create Sale
    |--------------------------------------------------------------------------
    */

    Route::post('/businesses/current/sales', [
        SaleController::class,
        'store',
    ])->middleware('permission:sales.create');
});


/*
|--------------------------------------------------------------------------
| Subscription Plans
|--------------------------------------------------------------------------
|
| Public endpoint.
|
| Businesses/users need to be able to view available plans
| before selecting a subscription.
|
*/

Route::get('/subscription-plans', [
    SubscriptionController::class,
    'plans',
]);


/*
|--------------------------------------------------------------------------
| Current Business Subscription
|--------------------------------------------------------------------------
|
| Requires:
|
| - authenticated user
| - active business context
|
*/

Route::middleware([
    'auth:sanctum',
    'business.context',
])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | View Current Subscription
    |--------------------------------------------------------------------------
    */

    Route::get('/businesses/current/subscription', [
        SubscriptionController::class,
        'current',
    ]);


    /*
    |--------------------------------------------------------------------------
    | Subscription Checkout
    |--------------------------------------------------------------------------
    |
    | Creates a pending subscription payment and initializes
    | the configured payment gateway.
    |
    | The subscription is NOT activated here.
    |
    */

    Route::post('/businesses/current/subscription/checkout', [
        SubscriptionController::class,
        'checkout',
    ]);
});


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
                'session_business_id' => session(
                    'current_business_id'
                ),
            ]);
        }
    );
}


/*
|--------------------------------------------------------------------------
| Paystack Webhooks
|--------------------------------------------------------------------------
|
| Paystack must be able to reach this endpoint without a
| MerchantOS authentication token.
|
*/

Route::post('/webhooks/paystack', [
    PaystackWebhookController::class,
    'handle',
]);