<?php

use App\Domains\Identity\Controllers\AuthController;
use App\Domains\Identity\Controllers\RoleController;
use App\Domains\Identity\Controllers\EmployeeRegistrationController;

use App\Domains\Product\Controllers\ProductController;
use App\Domains\Inventory\Controllers\InventoryController;

use App\Domains\Organization\Controllers\BusinessContextController;
use App\Domains\Organization\Controllers\BusinessController;
use App\Domains\Organization\Controllers\BusinessMemberController;
use App\Domains\Organization\Controllers\BusinessJoinRequestController;
use App\Domains\Organization\Controllers\BusinessTypeController;
use App\Domains\Organization\Controllers\BranchController;

use App\Domains\Subscription\Controllers\SubscriptionController;
use App\Domains\Receipt\Controllers\ReceiptController;
use App\Domains\Customer\Controllers\CustomerController;

use App\Domains\Sales\Http\Controllers\SaleController;

use App\Domains\Payment\Controllers\PaystackWebhookController;
use App\Domains\Payment\Http\Controllers\OpayWebhookController;

use App\Domains\Catalog\Controllers\CategoryController;
use App\Domains\Service\Controllers\ServiceController;

use App\Domains\Organization\Services\BusinessContextService;

use App\Http\Middleware\DebugSession;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Authentication
|--------------------------------------------------------------------------
*/

Route::post('/auth/register', [
    AuthController::class,
    'register',
]);

Route::post('/auth/register-employee', [
    EmployeeRegistrationController::class,
    'register',
]);

Route::post('/auth/login', [
    AuthController::class,
    'login',
])->middleware('throttle:login');

/*
|--------------------------------------------------------------------------
| Public Reference Data
|--------------------------------------------------------------------------
*/

Route::get('/business-types', [
    BusinessTypeController::class,
    'index',
]);

Route::get('/subscription-plans', [
    SubscriptionController::class,
    'plans',
]);

/*
|--------------------------------------------------------------------------
| Authenticated API
|--------------------------------------------------------------------------
|
| MerchantOS uses Laravel Sanctum SPA authentication.
|
| statefulApi() is configured globally in bootstrap/app.php.
|
| Do NOT add the web middleware to API routes.
|
*/

Route::middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Current User
    |--------------------------------------------------------------------------
    */

    Route::get('/auth/me', [
        AuthController::class,
        'me',
    ])->middleware('business.context');

    /*
    |--------------------------------------------------------------------------
    | Logout
    |--------------------------------------------------------------------------
    */

    Route::post('/auth/logout', [
        AuthController::class,
        'logout',
    ]);

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

    Route::post('/businesses', [
        BusinessController::class,
        'store',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Current Business
    |--------------------------------------------------------------------------
    |
    | This route must appear before /businesses/{business}.
    |
    */

    Route::get('/businesses/current', [
        BusinessController::class,
        'current',
    ])->middleware('business.context');

    /*
    |--------------------------------------------------------------------------
    | Specific Business
    |--------------------------------------------------------------------------
    */

    Route::get('/businesses/{business}', [
        BusinessController::class,
        'show',
    ]);

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

    /*
    |--------------------------------------------------------------------------
    | Business Context
    |--------------------------------------------------------------------------
    |
    | Legacy/context endpoints.
    |
    */

    Route::get('/business/current', [
        BusinessContextController::class,
        'current',
    ])->middleware('business.context');

    Route::post('/business/current/clear', [
        BusinessContextController::class,
        'clear',
    ])->middleware('business.context');

    /*
    |--------------------------------------------------------------------------
    | Employee Join Requests
    |--------------------------------------------------------------------------
    |
    | Employee submits a request to join a business using its Merchant ID.
    |
    | IMPORTANT:
    | This route intentionally does NOT use business.context because
    | the employee is not a member of the business yet.
    |
    */

    Route::post('/businesses/join-requests', [
        BusinessJoinRequestController::class,
        'store',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Business Join Request Management
    |--------------------------------------------------------------------------
    |
    | Only users with users.join_requests.review permission can manage
    | pending employee join requests.
    |
    | Owner + Manager receive this permission.
    |
    */

    Route::middleware([
        'business.context',
        'permission:users.join_requests.review',
    ])->group(function () {

        Route::get(
            '/businesses/current/join-requests',
            [
                BusinessJoinRequestController::class,
                'index',
            ]
        );

        Route::post(
            '/businesses/current/join-requests/{joinRequest}/approve',
            [
                BusinessJoinRequestController::class,
                'approve',
            ]
        );

        Route::post(
            '/businesses/current/join-requests/{joinRequest}/reject',
            [
                BusinessJoinRequestController::class,
                'reject',
            ]
        );
    });

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
| Remove Business Member
|--------------------------------------------------------------------------
*/

Route::delete('/businesses/current/members/{user}', [
    BusinessMemberController::class,
    'removeMember',
]);

    /*
    |--------------------------------------------------------------------------
    | Branch Management
    |--------------------------------------------------------------------------
    |
    | Branches are always scoped to the authenticated user's current
    | business through business.context.
    |
    | Current branch permissions:
    | - branches.view
    | - branches.create
    | - branches.update
    |
    | There is intentionally no DELETE route because the permission
    | catalog currently has no branches.delete permission.
    |
    */

    Route::get('/businesses/current/branches', [
        BranchController::class,
        'index',
    ])->middleware([
        'business.context',
        'permission:branches.view',
    ]);

    Route::post('/businesses/current/branches', [
        BranchController::class,
        'store',
    ])->middleware([
        'business.context',
        'permission:branches.create',
    ]);

    Route::put('/businesses/current/branches/{branch}', [
        BranchController::class,
        'update',
    ])->middleware([
        'business.context',
        'permission:branches.update',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Custom Role Management
    |--------------------------------------------------------------------------
    */

    Route::get('/businesses/current/roles', [
        RoleController::class,
        'index',
    ])->middleware([
        'business.context',
        'permission:roles.view',
        'subscription.capability:advanced_rbac',
    ]);

    Route::post('/businesses/current/roles', [
        RoleController::class,
        'store',
    ])->middleware([
        'business.context',
        'permission:roles.create',
        'subscription.capability:advanced_rbac',
    ]);

    Route::put('/businesses/current/roles/{role}', [
        RoleController::class,
        'update',
    ])->middleware([
        'business.context',
        'permission:roles.update',
        'subscription.capability:advanced_rbac',
    ]);

    Route::delete('/businesses/current/roles/{role}', [
        RoleController::class,
        'destroy',
    ])->middleware([
        'business.context',
        'permission:roles.delete',
        'subscription.capability:advanced_rbac',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Product Management
    |--------------------------------------------------------------------------
    */

    Route::get('/businesses/current/products', [
        ProductController::class,
        'index',
    ])->middleware([
        'business.context',
        'permission:products.view',
    ]);

    Route::post('/businesses/current/products', [
        ProductController::class,
        'store',
    ])->middleware([
        'business.context',
        'permission:products.create',
    ]);

    Route::get('/businesses/current/products/{product}', [
        ProductController::class,
        'show',
    ])->middleware([
        'business.context',
        'permission:products.view',
    ]);

    Route::put('/businesses/current/products/{product}', [
        ProductController::class,
        'update',
    ])->middleware([
        'business.context',
        'permission:products.update',
    ]);

    Route::delete('/businesses/current/products/{product}', [
        ProductController::class,
        'destroy',
    ])->middleware([
        'business.context',
        'permission:products.delete',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Product Units
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/businesses/current/products/{product}/units',
        [
            ProductController::class,
            'storeUnit',
        ]
    )->middleware([
        'business.context',
        'permission:products.update',
    ]);

    Route::put(
        '/businesses/current/products/{product}/units/{unit}',
        [
            ProductController::class,
            'updateUnit',
        ]
    )->middleware([
        'business.context',
        'permission:products.update',
    ]);

    Route::post(
        '/businesses/current/products/{product}/units/{unit}/base',
        [
            ProductController::class,
            'setBaseUnit',
        ]
    )->middleware([
        'business.context',
        'permission:products.update',
    ]);

    Route::delete(
        '/businesses/current/products/{product}/units/{unit}',
        [
            ProductController::class,
            'destroyUnit',
        ]
    )->middleware([
        'business.context',
        'permission:products.update',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Customer Management
    |--------------------------------------------------------------------------
    */

    Route::get('/businesses/current/customers', [
        CustomerController::class,
        'index',
    ])->middleware([
        'business.context',
        'permission:customers.view',
    ]);

    Route::post('/businesses/current/customers', [
        CustomerController::class,
        'store',
    ])->middleware([
        'business.context',
        'permission:customers.create',
    ]);

    Route::get('/businesses/current/customers/{customer}', [
        CustomerController::class,
        'show',
    ])->middleware([
        'business.context',
        'permission:customers.view',
    ]);

    Route::put('/businesses/current/customers/{customer}', [
        CustomerController::class,
        'update',
    ])->middleware([
        'business.context',
        'permission:customers.update',
    ]);

    Route::delete('/businesses/current/customers/{customer}', [
        CustomerController::class,
        'destroy',
    ])->middleware([
        'business.context',
        'permission:customers.delete',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Inventory Management
    |--------------------------------------------------------------------------
    */

    Route::get('/businesses/current/inventory', [
        InventoryController::class,
        'index',
    ])->middleware([
        'business.context',
        'permission:inventory.view',
    ]);

    Route::get('/businesses/current/inventory/{stock}', [
        InventoryController::class,
        'show',
    ])->middleware([
        'business.context',
        'permission:inventory.view',
    ]);

    Route::post('/businesses/current/inventory/receive', [
        InventoryController::class,
        'receive',
    ])->middleware([
        'business.context',
        'permission:inventory.receive',
    ]);

    Route::post('/businesses/current/inventory/adjust', [
        InventoryController::class,
        'adjust',
    ])->middleware([
        'business.context',
        'permission:inventory.adjust',
    ]);

    Route::get('/businesses/current/inventory/{stock}/movements', [
        InventoryController::class,
        'movements',
    ])->middleware([
        'business.context',
        'permission:inventory.view',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Sales
    |--------------------------------------------------------------------------
    |
    | Already inside auth:sanctum.
    | business.context is applied to this group.
    |
    */

    Route::middleware('business.context')->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Sales Dashboard
        |--------------------------------------------------------------------------
        */

        Route::get('/businesses/current/sales/dashboard', [
            SaleController::class,
            'dashboard',
        ])->middleware('permission:sales.view');

        /*
        |--------------------------------------------------------------------------
        | Sales History
        |--------------------------------------------------------------------------
        */

        Route::get('/businesses/current/sales', [
            SaleController::class,
            'index',
        ])->middleware('permission:sales.view');

        /*
        |--------------------------------------------------------------------------
        | Sale Details
        |--------------------------------------------------------------------------
        */

        Route::get('/businesses/current/sales/{sale}', [
            SaleController::class,
            'show',
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
    | Receipts
    |--------------------------------------------------------------------------
    */

    Route::get('/businesses/current/receipts', [
        ReceiptController::class,
        'index',
    ])->middleware([
        'business.context',
        'permission:receipts.view',
    ]);

    Route::get('/businesses/current/receipts/{receipt}', [
        ReceiptController::class,
        'show',
    ])->middleware([
        'business.context',
        'permission:receipts.view',
    ]);

    Route::get('/businesses/current/receipts/{receipt}/print', [
        ReceiptController::class,
        'print',
    ])->middleware([
        'business.context',
        'permission:receipts.print',
    ]);

    Route::get('/businesses/current/receipts/{receipt}/pdf', [
        ReceiptController::class,
        'pdf',
    ])->middleware([
        'business.context',
        'permission:receipts.print',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Current Business Subscription
    |--------------------------------------------------------------------------
    */

    Route::get('/businesses/current/subscription', [
        SubscriptionController::class,
        'current',
    ])->middleware('business.context');

    Route::post('/businesses/current/subscription/checkout', [
        SubscriptionController::class,
        'checkout',
    ])->middleware('business.context');

    /*
    |--------------------------------------------------------------------------
    | Catalog Categories
    |--------------------------------------------------------------------------
    */

    Route::get('/businesses/current/catalog/categories', [
        CategoryController::class,
        'index',
    ])->middleware([
        'business.context',
        'permission:categories.view',
    ]);

    Route::post('/businesses/current/catalog/categories', [
        CategoryController::class,
        'store',
    ])->middleware([
        'business.context',
        'permission:categories.create',
    ]);

    Route::get('/businesses/current/catalog/categories/{category}', [
        CategoryController::class,
        'show',
    ])->middleware([
        'business.context',
        'permission:categories.view',
    ]);

    Route::put('/businesses/current/catalog/categories/{category}', [
        CategoryController::class,
        'update',
    ])->middleware([
        'business.context',
        'permission:categories.update',
    ]);

    Route::delete('/businesses/current/catalog/categories/{category}', [
        CategoryController::class,
        'destroy',
    ])->middleware([
        'business.context',
        'permission:categories.delete',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Service Management
    |--------------------------------------------------------------------------
    */

    Route::get('/businesses/current/services', [
        ServiceController::class,
        'index',
    ])->middleware([
        'business.context',
        'permission:services.view',
    ]);

    Route::post('/businesses/current/services', [
        ServiceController::class,
        'store',
    ])->middleware([
        'business.context',
        'permission:services.create',
    ]);

    Route::get('/businesses/current/services/{service}', [
        ServiceController::class,
        'show',
    ])->middleware([
        'business.context',
        'permission:services.view',
    ]);

    Route::put('/businesses/current/services/{service}', [
        ServiceController::class,
        'update',
    ])->middleware([
        'business.context',
        'permission:services.update',
    ]);

    Route::delete('/businesses/current/services/{service}', [
        ServiceController::class,
        'destroy',
    ])->middleware([
        'business.context',
        'permission:services.delete',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Temporary Authentication Debugging
    |--------------------------------------------------------------------------
    |
    | Remove these routes after Sanctum authentication is confirmed.
    |
    */

    Route::get('/debug/auth-context', function (Request $request) {

        return response()->json([
            'authenticated' => $request->user() !== null,
            'user_id' => $request->user()?->id,
            'session_id' => $request->session()->getId(),
            'current_business_id' => session('current_business_id'),
        ]);
    });
});

/*
|--------------------------------------------------------------------------
| Temporary CSRF / Session Debugging
|--------------------------------------------------------------------------
|
| These routes are temporary and should be removed after authentication
| debugging is complete.
|
*/

Route::middleware(DebugSession::class)
    ->get('/debug/csrf-session', function (Request $request) {

        return response()->json([
            'session_id' => $request->session()->getId(),
            'csrf_token' => $request->session()->token(),
        ]);
    });

Route::post('/debug/ping', function (Request $request) {

    return response()->json([
        'received' => true,
        'method' => $request->method(),
        'has_session' => $request->hasSession(),
    ]);
});

/*
|--------------------------------------------------------------------------
| Paystack Webhooks
|--------------------------------------------------------------------------
|
| Paystack must be able to reach this endpoint without MerchantOS
| authentication.
|
*/

Route::post('/webhooks/paystack', [
    PaystackWebhookController::class,
    'handle',
]);

/*
|--------------------------------------------------------------------------
| OPay Webhook
|--------------------------------------------------------------------------
*/

Route::post('/webhooks/opay', [
    OpayWebhookController::class,
    'handle',
]);