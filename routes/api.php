<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CompanySettingController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerLevelController;
use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SlipController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VehicleTypeController;
use App\Http\Controllers\Api\TrackingController;
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

// Public document tracking (scan any document number → unified timeline)
Route::get('/tracking/{number}', [TrackingController::class, 'show']);

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

    // Company Settings
    Route::middleware('permission:settings.view')->get('/company-settings', [CompanySettingController::class, 'index']);
    Route::middleware('permission:settings.edit')->group(function () {
        Route::put('/company-settings', [CompanySettingController::class, 'update']);
        Route::post('/company-settings/logo', [CompanySettingController::class, 'uploadLogo']);
        Route::delete('/company-settings/logo', [CompanySettingController::class, 'deleteLogo']);
        Route::get('/company-settings/slip2go', [CompanySettingController::class, 'getSlip2go']);
        Route::put('/company-settings/slip2go', [CompanySettingController::class, 'updateSlip2go']);
        Route::post('/company-settings/slip2go/test', [CompanySettingController::class, 'testSlip2go']);
    });

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

    // Roles & Permissions
    Route::middleware('permission:roles.view')->group(function () {
        Route::get('/roles', [RoleController::class, 'index']);
        Route::get('/roles/{role}', [RoleController::class, 'show']);
        Route::get('/permissions', [RoleController::class, 'permissions']);
    });
    Route::middleware('permission:roles.manage')->group(function () {
        Route::post('/roles', [RoleController::class, 'store']);
        Route::put('/roles/{role}', [RoleController::class, 'update']);
        Route::patch('/roles/{role}', [RoleController::class, 'update']);
        Route::delete('/roles/{role}', [RoleController::class, 'destroy']);
        Route::post('/assign-role', [RoleController::class, 'assignRole']);
    });

    // Vehicle Types
    Route::middleware('permission:vehicle-types.view')->group(function () {
        Route::get('/vehicle-types', [VehicleTypeController::class, 'index']);
        Route::get('/vehicle-types/suggest', [VehicleTypeController::class, 'suggest']);
        Route::get('/vehicle-types/{vehicleType}', [VehicleTypeController::class, 'show']);
    });
    Route::middleware('permission:vehicle-types.manage')->group(function () {
        Route::post('/vehicle-types', [VehicleTypeController::class, 'store']);
        Route::put('/vehicle-types/{vehicleType}', [VehicleTypeController::class, 'update']);
        Route::delete('/vehicle-types/{vehicleType}', [VehicleTypeController::class, 'destroy']);
    });

    // Customer Levels
    Route::middleware('permission:customer-levels.view')->group(function () {
        Route::get('/customer-levels', [CustomerLevelController::class, 'index']);
        Route::get('/customer-levels/{customerLevel}', [CustomerLevelController::class, 'show']);
    });
    Route::middleware('permission:customer-levels.manage')->group(function () {
        Route::post('/customer-levels', [CustomerLevelController::class, 'store']);
        Route::put('/customer-levels/{customerLevel}', [CustomerLevelController::class, 'update']);
        Route::delete('/customer-levels/{customerLevel}', [CustomerLevelController::class, 'destroy']);
    });

    // Customers
    Route::middleware('permission:customers.view')->group(function () {
        Route::get('/customers/next-code', [CustomerController::class, 'nextCode']);
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::get('/customers/{customer}', [CustomerController::class, 'show']);
        Route::get('/customers/{customer}/history', [CustomerController::class, 'history']);
    });
    Route::middleware('permission:customers.create')->post('/customers', [CustomerController::class, 'store']);
    Route::middleware('permission:customers.edit')->match(['put', 'patch'], '/customers/{customer}', [CustomerController::class, 'update']);
    Route::middleware('permission:customers.delete')->delete('/customers/{customer}', [CustomerController::class, 'destroy']);

    // Quotations
    Route::middleware('account.scope')->group(function () {
        Route::middleware('permission:quotations.view')->group(function () {
            Route::get('/quotations/next-number', [QuotationController::class, 'nextNumber']);
            Route::get('/quotations', [QuotationController::class, 'index']);
            Route::get('/quotations/{quotation}', [QuotationController::class, 'show']);
            Route::get('/quotations/{quotation}/revisions', [QuotationController::class, 'revisions']);
        });
        Route::middleware('permission:quotations.create')->group(function () {
            Route::post('/quotations', [QuotationController::class, 'store']);
            Route::post('/quotations/{quotation}/duplicate', [QuotationController::class, 'duplicate']);
        });
        Route::middleware('permission:quotations.edit')->match(['put', 'patch'], '/quotations/{quotation}', [QuotationController::class, 'update']);
        Route::middleware('permission:quotations.delete')->delete('/quotations/{quotation}', [QuotationController::class, 'destroy']);

        // Orders
        Route::middleware('permission:orders.view')->group(function () {
            Route::get('/orders', [OrderController::class, 'index']);
            Route::get('/orders/{order}', [OrderController::class, 'show']);
            Route::get('/orders/{order}/timeline', [OrderController::class, 'timeline']);
        });
        Route::middleware('permission:orders.edit')->match(['put', 'patch'], '/orders/{order}', [OrderController::class, 'update']);

        // Payments
        Route::middleware('permission:payments.view')->group(function () {
            Route::get('/payments', [PaymentController::class, 'index']);
            Route::get('/orders/{order}/pending-payments', [PaymentController::class, 'pendingByOrder']);
            Route::get('/payments/{payment}', [PaymentController::class, 'show']);
            // Slip gallery (shared slips)
            Route::get('/slips', [SlipController::class, 'index']);
            Route::get('/slips/{slip}', [SlipController::class, 'show']);
        });
        Route::middleware('permission:payments.create')->group(function () {
            Route::post('/orders/{order}/payments', [PaymentController::class, 'store']);
            Route::post('/payments/{payment}/resubmit', [PaymentController::class, 'resubmit']);
            Route::post('/payments/verify-slip', [PaymentController::class, 'verifySlip']);
            Route::post('/slips', [SlipController::class, 'store']);
        });
        Route::middleware('permission:payments.approve')->group(function () {
            Route::post('/payments/{payment}/approve', [PaymentController::class, 'approve']);
            Route::post('/orders/{order}/approve-payments', [PaymentController::class, 'approveOrderPayments']);
        });
        Route::middleware('permission:payments.reject')->post('/payments/{payment}/reject', [PaymentController::class, 'reject']);

        // Invoices
        Route::middleware('permission:invoices.view')->group(function () {
            Route::get('/invoices/pending', [InvoiceController::class, 'pending']);
            Route::get('/invoices', [InvoiceController::class, 'index']);
            Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
        });
        Route::middleware('permission:invoices.create')->post('/orders/{order}/invoices', [InvoiceController::class, 'store']);
        Route::middleware('permission:invoices.cancel')->post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel']);

        // Deliveries
        Route::middleware('permission:deliveries.view')->group(function () {
            Route::get('/deliveries', [DeliveryController::class, 'index']);
            Route::get('/deliveries/daily-summary', [DeliveryController::class, 'dailySummary']);
            Route::get('/deliveries/calendar', [DeliveryController::class, 'calendar']);
            Route::get('/deliveries/{delivery}', [DeliveryController::class, 'show']);
            Route::get('/orders/{order}/delivery-remaining', [DeliveryController::class, 'orderRemaining']);
        });
        Route::middleware('permission:deliveries.create')->post('/orders/{order}/deliveries', [DeliveryController::class, 'store']);
        Route::middleware('permission:deliveries.confirm')->post('/deliveries/{delivery}/confirm', [DeliveryController::class, 'confirmDelivery']);
        Route::middleware('permission:deliveries.cancel')->post('/deliveries/{delivery}/cancel', [DeliveryController::class, 'cancel']);

        // Reports (scoped per account)
        Route::middleware('permission:reports.view')->group(function () {
            Route::get('/reports/dashboard', [ReportController::class, 'dashboard']);
            Route::get('/reports/sales-by-seller', [ReportController::class, 'salesBySeller']);
            Route::get('/reports/inactive-customers', [ReportController::class, 'inactiveCustomers']);
            Route::get('/reports/ar-aging', [ReportController::class, 'arAging']);
            Route::get('/reports/monthly-sales', [ReportController::class, 'monthlySales']);
            Route::get('/reports/invoices', [ReportController::class, 'invoiceReport']);
            Route::get('/reports/sales-by-customer', [ReportController::class, 'salesByCustomer']);
            Route::get('/reports/sales-by-product', [ReportController::class, 'salesByProduct']);
        });
    });
});
