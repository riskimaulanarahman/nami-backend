<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BilliardPackageController;
use App\Http\Controllers\Api\CashierShiftController;
use App\Http\Controllers\Api\CashierShiftExpenseController;
use App\Http\Controllers\Api\IngredientController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\MenuCategoryController;
use App\Http\Controllers\Api\MenuItemController;
use App\Http\Controllers\Api\OpenBillController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentOptionController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\StockAdjustmentController;
use App\Http\Controllers\Api\TableController;
use App\Http\Controllers\Api\TableLayoutController;
use App\Http\Controllers\Api\WaitingListController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TenantController;

/*
|--------------------------------------------------------------------------
| Central Routes
|--------------------------------------------------------------------------
*/
Route::post('/tenants/register', [TenantController::class, 'register']);
Route::post('/auth/tenant-login', [AuthController::class, 'tenantLogin']);

Route::middleware(['auth:sanctum', 'tenant.context'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
});

Route::middleware(['auth:sanctum', 'tenant.context', 'tenant.token'])->group(function () {
    Route::get('/auth/staff-list', [AuthController::class, 'staffList']);
    Route::post('/auth/staff-pin-login', [AuthController::class, 'staffPinLogin']);
});

Route::middleware(['auth:sanctum', 'tenant.context', 'staff.token'])->group(function () {
    // Auth
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/verify-admin-pin', [AuthController::class, 'verifyAdminPin']);
    Route::post('/auth/verify-staff-pin', [AuthController::class, 'verifyStaffPin']);

    // ── Read-only endpoints (no shift required) ──────────────────────────
    Route::get('/tables', [TableController::class, 'index']);
    Route::get('/tables/{table}', [TableController::class, 'show']);
    Route::get('/tables/{table}/bill', [TableController::class, 'bill']);

    Route::get('/menu-items', [MenuItemController::class, 'index']);
    Route::get('/menu-items/{menuItem}', [MenuItemController::class, 'show']);

    Route::get('/menu-categories', [MenuCategoryController::class, 'index']);

    Route::get('/ingredients', [IngredientController::class, 'index']);
    Route::get('/ingredients/low-stock', [IngredientController::class, 'lowStock']);
    Route::get('/ingredients/{ingredient}', [IngredientController::class, 'show']);

    Route::get('/payment-options', [PaymentOptionController::class, 'index']);
    Route::get('/billiard-packages', [BilliardPackageController::class, 'index']);

    Route::get('/open-bills', [OpenBillController::class, 'index']);
    Route::get('/open-bills/{openBill}', [OpenBillController::class, 'show']);
    Route::get('/open-bills/{openBill}/receipt', [OpenBillController::class, 'receipt']);
    Route::get('/open-bills/{openBill}/totals', [OpenBillController::class, 'totals']);

    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);

    Route::get('/cashier-shifts', [CashierShiftController::class, 'index']);
    Route::get('/cashier-shifts/active', [CashierShiftController::class, 'active']);
    Route::get('/cashier-shifts/{cashierShift}', [CashierShiftController::class, 'show']);
    Route::get('/cashier-shifts/{cashierShift}/transactions', [CashierShiftController::class, 'transactions']);
    Route::get('/cashier-shifts/{cashierShift}/expenses', [CashierShiftExpenseController::class, 'index']);

    Route::get('/waiting-list', [WaitingListController::class, 'index']);

    Route::get('/members', [MemberController::class, 'index']);
    Route::get('/members/{member}', [MemberController::class, 'show']);
    Route::get('/members/{member}/points', [MemberController::class, 'points']);

    Route::get('/settings', [SettingsController::class, 'show']);

    Route::get('/table-layout', [TableLayoutController::class, 'index']);

    Route::get('/stock-adjustments', [StockAdjustmentController::class, 'index']);

    // ── Shift management (no shift middleware, but requires auth) ─────────
    Route::post('/cashier-shifts/open', [CashierShiftController::class, 'open']);
    Route::post('/cashier-shifts/close', [CashierShiftController::class, 'close']);

    // ── POS operations (requires active shift) ───────────────────────────
    Route::middleware(\App\Http\Middleware\EnsureActiveShift::class)->group(function () {
        // Table session operations
        Route::post('/tables/{table}/start-session', [TableController::class, 'startSession']);
        Route::post('/tables/{table}/package-expiry/acknowledge', [TableController::class, 'acknowledgePackageExpiry']);
        Route::post('/tables/{table}/extend-package', [TableController::class, 'extendPackage']);
        Route::post('/tables/{table}/convert-to-open-bill', [TableController::class, 'convertPackageToOpenBill']);
        Route::post('/tables/{table}/end-session', [TableController::class, 'endSession']);
        Route::post('/tables/{table}/checkout', [TableController::class, 'checkout']);
        Route::post('/tables/{table}/add-order', [TableController::class, 'addOrder']);
        Route::post('/tables/{table}/draft-orders', [TableController::class, 'appendDraftOrders']);
        Route::delete('/tables/{table}/remove-order/{menuItemId}', [TableController::class, 'removeOrder']);
        Route::put('/tables/{table}/update-order/{menuItemId}', [TableController::class, 'updateOrder']);

        // Open Bill operations
        Route::post('/open-bills', [OpenBillController::class, 'store']);
        Route::put('/open-bills/{openBill}', [OpenBillController::class, 'update']);
        Route::delete('/open-bills/{openBill}', [OpenBillController::class, 'destroy']);
        Route::post('/open-bills/{openBill}/assign-table', [OpenBillController::class, 'assignTable']);
        Route::post('/open-bills/{openBill}/add-item', [OpenBillController::class, 'addItem']);
        Route::delete('/open-bills/{openBill}/remove-item', [OpenBillController::class, 'removeItem']);
        Route::put('/open-bills/{openBill}/update-item', [OpenBillController::class, 'updateItem']);
        Route::post('/open-bills/{openBill}/attach-member', [OpenBillController::class, 'attachMember']);
        Route::post('/open-bills/{openBill}/checkout', [OpenBillController::class, 'checkout']);

        // Waiting list
        Route::post('/waiting-list', [WaitingListController::class, 'store']);
        Route::put('/waiting-list/{waitingListEntry}', [WaitingListController::class, 'update']);
        Route::post('/waiting-list/{waitingListEntry}/seat', [WaitingListController::class, 'seat']);
        Route::post('/waiting-list/{waitingListEntry}/cancel', [WaitingListController::class, 'cancel']);

        // Members
        Route::post('/members', [MemberController::class, 'store']);
        Route::put('/members/{member}', [MemberController::class, 'update']);
        Route::delete('/members/{member}', [MemberController::class, 'destroy']);

        // Expenses
        Route::post('/cashier-shifts/expenses', [CashierShiftExpenseController::class, 'store']);
        Route::delete('/cashier-shifts/expenses/{cashierShiftExpense}', [CashierShiftExpenseController::class, 'destroy']);

        // Orders - refund
        Route::post('/orders/{order}/refund', [OrderController::class, 'refund']);

        // Stock adjustments
        Route::post('/stock-adjustments', [StockAdjustmentController::class, 'store']);
    });

    // ── Admin-only operations ────────────────────────────────────────────
    Route::middleware(\App\Http\Middleware\EnsureAdminRole::class)->group(function () {
        // Staff CRUD
        Route::apiResource('/staff', StaffController::class);

        // Table CRUD (admin create/update/delete)
        Route::post('/tables', [TableController::class, 'store']);
        Route::put('/tables/{table}', [TableController::class, 'update']);
        Route::delete('/tables/{table}', [TableController::class, 'destroy']);

        // Menu CRUD
        Route::post('/menu-items', [MenuItemController::class, 'store']);
        Route::put('/menu-items/{menuItem}', [MenuItemController::class, 'update']);
        Route::delete('/menu-items/{menuItem}', [MenuItemController::class, 'destroy']);

        Route::post('/menu-categories', [MenuCategoryController::class, 'store']);
        Route::put('/menu-categories/{menuCategory}', [MenuCategoryController::class, 'update']);
        Route::delete('/menu-categories/{menuCategory}', [MenuCategoryController::class, 'destroy']);

        // Ingredients
        Route::post('/ingredients', [IngredientController::class, 'store']);
        Route::put('/ingredients/{ingredient}', [IngredientController::class, 'update']);
        Route::patch('/ingredients/{ingredient}/archive', [IngredientController::class, 'archive']);
        Route::patch('/ingredients/{ingredient}/restore', [IngredientController::class, 'restore']);
        Route::delete('/ingredients/{ingredient}', [IngredientController::class, 'destroy']);

        // Payment options
        Route::post('/payment-options', [PaymentOptionController::class, 'store']);
        Route::put('/payment-options/{paymentOption}', [PaymentOptionController::class, 'update']);
        Route::delete('/payment-options/{paymentOption}', [PaymentOptionController::class, 'destroy']);

        // Billiard packages
        Route::post('/billiard-packages', [BilliardPackageController::class, 'store']);
        Route::put('/billiard-packages/{billiardPackage}', [BilliardPackageController::class, 'update']);
        Route::delete('/billiard-packages/{billiardPackage}', [BilliardPackageController::class, 'destroy']);

        // Settings
        Route::put('/settings', [SettingsController::class, 'update']);

        // Table layout
        Route::put('/table-layout/{tableId}', [TableLayoutController::class, 'update']);
        Route::post('/table-layout/reset', [TableLayoutController::class, 'reset']);

        // Reports
        Route::get('/reports/dashboard', [ReportController::class, 'dashboard']);
        Route::get('/reports/billiard', [ReportController::class, 'billiard']);
        Route::get('/reports/fnb', [ReportController::class, 'fnb']);
    });
});
