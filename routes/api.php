<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CustomerAccountController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerDashboardController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\LoyaltyController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\PurchaseHistoryController;
use App\Http\Controllers\Api\RedemptionController;
use App\Http\Controllers\Api\RewardController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WarehouseController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Authorization uses the `permission` middleware; the role -> permission map
| lives in App\Support\Permissions.
|
*/

// Public routes
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');

// Protected routes
Route::middleware(['auth:sanctum'])->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Staff users (account creation is no longer public)
    Route::middleware('permission:users.manage')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::apiResource('users', UserController::class)->except(['show']);
    });

    // Branches
    Route::get('/branches', [BranchController::class, 'index']);
    Route::middleware('permission:branches.manage')->group(function () {
        Route::post('/branches', [BranchController::class, 'store']);
        Route::put('/branches/{branch}', [BranchController::class, 'update']);
    });

    // Products
    Route::get('/products/low-stock', [ProductController::class, 'lowStock'])->middleware('permission:products.view');
    Route::apiResource('products', ProductController::class)->only(['index', 'show'])->middleware('permission:products.view');
    Route::apiResource('products', ProductController::class)->only(['store', 'update', 'destroy'])->middleware('permission:products.manage');

    // Orders / Sales
    Route::get('/orders/stats/sales', [OrderController::class, 'salesStats'])->middleware('permission:reports.sales');
    Route::apiResource('orders', OrderController::class)->only(['index', 'show'])->middleware('permission:orders.view');
    Route::apiResource('orders', OrderController::class)->only(['store', 'update'])->middleware('permission:orders.manage');

    // Inventory
    Route::get('/inventory/summary', [InventoryController::class, 'summary'])->middleware('permission:inventory.view');
    Route::apiResource('inventory', InventoryController::class)->only(['index', 'show'])->middleware('permission:inventory.view');
    Route::middleware('permission:inventory.manage')->group(function () {
        Route::post('/inventory/{inventory}/update-stock', [InventoryController::class, 'updateStock']);
        Route::post('/inventory/transfer', [InventoryController::class, 'transferStock']);
        Route::apiResource('inventory', InventoryController::class)->only(['store', 'update', 'destroy']);
    });

    // Warehouses
    Route::apiResource('warehouses', WarehouseController::class)->only(['index', 'show'])->middleware('permission:warehouses.view');
    Route::apiResource('warehouses', WarehouseController::class)->only(['store', 'update', 'destroy'])->middleware('permission:warehouses.manage');

    /*
    |----------------------------------------------------------------------
    | Customer Management Module
    |----------------------------------------------------------------------
    */
    Route::get('/customer-management/options', [CustomerDashboardController::class, 'options']);
    Route::get('/customer-management/dashboard', [CustomerDashboardController::class, 'dashboard'])->middleware('permission:customers.dashboard');
    Route::get('/customer-management/reports', [CustomerDashboardController::class, 'reports'])->middleware('permission:customers.reports');
    Route::post('/customer-management/announcements', [CustomerAccountController::class, 'announce'])->middleware('permission:customers.notify');

    // Module 1 & 6: registration, profile, verification, status, maintenance
    Route::get('/customers/lookup', [CustomerController::class, 'lookup'])->middleware('permission:customers.lookup');
    Route::post('/customers/check-duplicates', [CustomerController::class, 'checkDuplicates'])->middleware('permission:customers.create,customers.update');
    Route::get('/customers', [CustomerController::class, 'index'])->middleware('permission:customers.view');
    Route::post('/customers', [CustomerController::class, 'store'])->middleware('permission:customers.create');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->middleware('permission:customers.view');
    Route::put('/customers/{customer}', [CustomerController::class, 'update'])->middleware('permission:customers.update');
    Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->middleware('permission:customers.delete');
    Route::post('/customers/{customer}/verify', [CustomerAccountController::class, 'verify'])->middleware('permission:customers.verify');
    Route::post('/customers/{customer}/activate', [CustomerAccountController::class, 'activate'])->middleware('permission:customers.status');
    Route::post('/customers/{customer}/status', [CustomerAccountController::class, 'changeStatus'])->middleware('permission:customers.status');
    Route::get('/customers/{customer}/account-logs', [CustomerAccountController::class, 'accountLogs'])->middleware('permission:customers.view');
    Route::get('/customers/{customer}/notifications', [CustomerAccountController::class, 'notifications'])->middleware('permission:customers.view');

    // Module 2: loyalty membership and points
    Route::get('/loyalty/settings', [LoyaltyController::class, 'settings'])->middleware('permission:loyalty.view');
    Route::put('/loyalty/settings', [LoyaltyController::class, 'updateSettings'])->middleware('permission:loyalty.configure');
    Route::put('/loyalty/levels/{level}', [LoyaltyController::class, 'updateLevel'])->middleware('permission:loyalty.configure');
    Route::get('/loyalty/calculate', [LoyaltyController::class, 'calculate'])->middleware('permission:loyalty.view');
    Route::get('/loyalty/members', [LoyaltyController::class, 'members'])->middleware('permission:loyalty.view');
    Route::get('/loyalty/transactions', [LoyaltyController::class, 'transactions'])->middleware('permission:loyalty.view');
    Route::post('/loyalty/orders/{order}/sync', [LoyaltyController::class, 'syncOrder'])->middleware('permission:loyalty.sync');
    Route::post('/loyalty/expire', [LoyaltyController::class, 'runExpiry'])->middleware('permission:loyalty.configure');
    Route::get('/customers/{customer}/loyalty', [LoyaltyController::class, 'customerLoyalty'])->middleware('permission:loyalty.view');
    Route::post('/customers/{customer}/loyalty/enroll', [LoyaltyController::class, 'enroll'])->middleware('permission:loyalty.enroll');
    Route::post('/customers/{customer}/loyalty/level', [LoyaltyController::class, 'setLevel'])->middleware('permission:loyalty.configure');
    Route::post('/customers/{customer}/loyalty/adjust', [LoyaltyController::class, 'adjust'])->middleware('permission:loyalty.adjust');

    // Module 3: purchase history
    Route::get('/purchase-history', [PurchaseHistoryController::class, 'index'])->middleware('permission:customers.purchases');
    Route::get('/purchase-history/{order}/verify', [PurchaseHistoryController::class, 'verify'])->middleware('permission:customers.purchases.investigate');
    Route::post('/purchase-history/{order}/associate-customer', [PurchaseHistoryController::class, 'associate'])->middleware('permission:customers.purchases.investigate,orders.manage');
    Route::get('/customers/{customer}/purchases', [PurchaseHistoryController::class, 'forCustomer'])->middleware('permission:customers.purchases');

    // Module 4: feedback and complaints (assigned staff are checked in the controller)
    Route::get('/feedback', [FeedbackController::class, 'index']);
    Route::post('/feedback', [FeedbackController::class, 'store'])->middleware('permission:feedback.record');
    Route::get('/feedback/{feedback}', [FeedbackController::class, 'show']);
    Route::put('/feedback/{feedback}', [FeedbackController::class, 'update'])->middleware('permission:feedback.respond');
    Route::post('/feedback/{feedback}/assign', [FeedbackController::class, 'assign'])->middleware('permission:feedback.assign');
    Route::post('/feedback/{feedback}/responses', [FeedbackController::class, 'respond']);
    Route::post('/feedback/{feedback}/status', [FeedbackController::class, 'changeStatus']);

    // Module 5: rewards and redemption
    Route::get('/rewards', [RewardController::class, 'index'])->middleware('permission:rewards.view');
    Route::get('/rewards/{reward}', [RewardController::class, 'show'])->middleware('permission:rewards.view');
    Route::post('/rewards', [RewardController::class, 'store'])->middleware('permission:rewards.manage');
    Route::put('/rewards/{reward}', [RewardController::class, 'update'])->middleware('permission:rewards.manage');
    Route::delete('/rewards/{reward}', [RewardController::class, 'destroy'])->middleware('permission:rewards.manage');
    Route::get('/customers/{customer}/rewards', [RewardController::class, 'forCustomer'])->middleware('permission:redemptions.process');
    Route::get('/redemptions', [RedemptionController::class, 'index'])->middleware('permission:redemptions.view');
    Route::post('/redemptions/check', [RedemptionController::class, 'check'])->middleware('permission:redemptions.process');
    Route::post('/redemptions', [RedemptionController::class, 'store'])->middleware('permission:redemptions.process');
    Route::get('/redemptions/{redemption}', [RedemptionController::class, 'show'])->middleware('permission:redemptions.view');
    Route::post('/redemptions/{redemption}/cancel', [RedemptionController::class, 'cancel'])->middleware('permission:redemptions.cancel');
});
