<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CompanySettingController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerLevelController;
use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VehicleTypeController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

// PDF export (auth via query token because window.open cannot send Bearer header)
Route::get('/quotations/{quotation}/pdf', [QuotationController::class, 'exportPdf']);

// Public order status (for QR code scan)
Route::get('/orders/status/{orderNumber}', [OrderController::class, 'publicStatus']);

// Public delivery lookup (for QR code scan)
Route::get('/deliveries/lookup/{deliveryNumber}', [DeliveryController::class, 'lookupByNumber']);

// Delivery PDF export (auth via query token)
Route::get('/deliveries/{delivery}/pdf', [DeliveryController::class, 'exportPdf']);

// Invoice PDF export (auth via query token)
Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'exportPdf']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Profile
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::put('/profile/password', [ProfileController::class, 'changePassword']);

    // Products & Categories
    Route::middleware('permission:products.view')->group(function () {
        Route::apiResource('products', ProductController::class)->only(['index', 'show']);
        Route::apiResource('categories', CategoryController::class)->only(['index', 'show']);
    });
    Route::middleware('permission:products.create')->post('/products', [ProductController::class, 'store']);
    Route::middleware('permission:products.edit')->put('/products/{product}', [ProductController::class, 'update']);
    Route::middleware('permission:products.delete')->delete('/products/{product}', [ProductController::class, 'destroy']);
    Route::middleware('permission:categories.create')->post('/categories', [CategoryController::class, 'store']);
    Route::middleware('permission:categories.edit')->put('/categories/{category}', [CategoryController::class, 'update']);
    Route::middleware('permission:categories.delete')->delete('/categories/{category}', [CategoryController::class, 'destroy']);

    // Users management (permission-based)
    Route::middleware('permission:users.view')->get('/users', [UserController::class, 'index']);
    Route::middleware('permission:users.view')->get('/users/{user}', [UserController::class, 'show']);
    Route::middleware('permission:users.create')->post('/users', [UserController::class, 'store']);
    Route::middleware('permission:users.edit')->put('/users/{user}', [UserController::class, 'update']);
    Route::middleware('permission:users.delete')->delete('/users/{user}', [UserController::class, 'destroy']);

    // Roles & Permissions (admin only)
    Route::middleware('role:admin')->group(function () {
        Route::apiResource('roles', RoleController::class);
        Route::get('/permissions', [RoleController::class, 'permissions']);
        Route::post('/assign-role', [RoleController::class, 'assignRole']);
    });

    // Vehicle Types
    Route::get('/vehicle-types', [VehicleTypeController::class, 'index']);
    Route::get('/vehicle-types/suggest', [VehicleTypeController::class, 'suggest']);
    Route::get('/vehicle-types/{vehicleType}', [VehicleTypeController::class, 'show']);
    Route::middleware('role:admin')->group(function () {
        Route::post('/vehicle-types', [VehicleTypeController::class, 'store']);
        Route::put('/vehicle-types/{vehicleType}', [VehicleTypeController::class, 'update']);
        Route::delete('/vehicle-types/{vehicleType}', [VehicleTypeController::class, 'destroy']);
    });

    // Customer Levels
    Route::get('/customer-levels', [CustomerLevelController::class, 'index']);
    Route::get('/customer-levels/{customerLevel}', [CustomerLevelController::class, 'show']);
    Route::middleware('role:admin')->group(function () {
        Route::post('/customer-levels', [CustomerLevelController::class, 'store']);
        Route::put('/customer-levels/{customerLevel}', [CustomerLevelController::class, 'update']);
        Route::delete('/customer-levels/{customerLevel}', [CustomerLevelController::class, 'destroy']);
    });

    // Customers
    Route::get('/customers/next-code', [CustomerController::class, 'nextCode']);
    Route::apiResource('customers', CustomerController::class);

    // Quotations
    Route::get('/quotations/next-number', [QuotationController::class, 'nextNumber']);
    Route::post('/quotations/{quotation}/duplicate', [QuotationController::class, 'duplicate']);
    Route::get('/quotations/{quotation}/revisions', [QuotationController::class, 'revisions']);
    Route::apiResource('quotations', QuotationController::class);

    // Company Settings
    Route::get('/company-settings', [CompanySettingController::class, 'index']);
    Route::put('/company-settings', [CompanySettingController::class, 'update']);
    Route::post('/company-settings/logo', [CompanySettingController::class, 'uploadLogo']);
    Route::delete('/company-settings/logo', [CompanySettingController::class, 'deleteLogo']);

    // Slip2Go Settings
    Route::match(['get', 'put'], '/company-settings/slip2go', [PaymentController::class, 'slip2goSettings']);
    Route::post('/company-settings/slip2go/test', [PaymentController::class, 'slip2goTest']);

    // Orders
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::put('/orders/{order}', [OrderController::class, 'update']);
    Route::get('/orders/{order}/timeline', [OrderController::class, 'timeline']);

    // Payments
    Route::get('/payments', [PaymentController::class, 'index']);
    Route::get('/payments/{payment}', [PaymentController::class, 'show']);
    Route::post('/orders/{order}/payments', [PaymentController::class, 'store']);
    Route::post('/payments/{payment}/approve', [PaymentController::class, 'approve']);
    Route::post('/payments/{payment}/reject', [PaymentController::class, 'reject']);
    Route::post('/payments/{payment}/resubmit', [PaymentController::class, 'resubmit']);
    Route::post('/payments/verify-slip', [PaymentController::class, 'verifySlip']);

    // Invoices
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
    Route::post('/orders/{order}/invoices', [InvoiceController::class, 'store']);
    Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel']);

    // Deliveries
    Route::get('/deliveries', [DeliveryController::class, 'index']);
    Route::get('/deliveries/{delivery}', [DeliveryController::class, 'show']);
    Route::post('/orders/{order}/deliveries', [DeliveryController::class, 'store']);
    Route::get('/orders/{order}/delivery-remaining', [DeliveryController::class, 'orderRemaining']);
    Route::post('/deliveries/{delivery}/confirm', [DeliveryController::class, 'confirmDelivery']);
    Route::post('/deliveries/{delivery}/cancel', [DeliveryController::class, 'cancel']);
});
