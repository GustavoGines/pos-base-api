<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PosController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\CashShiftController;

use App\Http\Controllers\Api\AuthController;

Route::prefix('auth')->group(function () {
    Route::post('/verify-pin', [AuthController::class, 'verifyPin'])->middleware('throttle:5,1');
});

Route::apiResource('users', \App\Http\Controllers\Api\UserController::class);

use App\Http\Controllers\Api\CustomerController;

Route::apiResource('customers', CustomerController::class);
Route::post('/customers/{customer}/payments', [CustomerController::class, 'registerPayment']);
Route::get('/customers/{customer}/pending-sales', [CustomerController::class, 'getPendingSales']);

Route::apiResource('payment-methods', \App\Http\Controllers\Api\PaymentMethodController::class);

Route::get('/settings', [SettingController::class, 'index']);
Route::put('/settings', [SettingController::class, 'update']);
Route::post('/settings/license', [SettingController::class, 'updateLicense']);
Route::post('/settings/license/sync', [SettingController::class, 'syncLicense']);

use App\Http\Controllers\Api\SalesController;

Route::prefix('pos')->group(function () {
    Route::get('/products/search', [PosController::class, 'searchProducts']);
    Route::post('/sales', [PosController::class, 'processSale']);
});

Route::get('/sales', [SalesController::class, 'index']);
Route::get('/sales/pending', [SalesController::class, 'pending']);
Route::post('/sales/{sale}/void', [SalesController::class, 'void']);
Route::put('/sales/{sale}/pay', [SalesController::class, 'pay']);

use App\Http\Controllers\Api\StockController;

Route::prefix('catalog')->group(function () {
    Route::post('/products/bulk-delete', [CatalogController::class, 'bulkDelete']);
    Route::put('/products/bulk-update', [CatalogController::class, 'bulkUpdate']);
    Route::put('/products/bulk-price-update', [CatalogController::class, 'bulkPriceUpdate']);
    Route::post('/products/{product}/adjust-stock', [StockController::class, 'adjust']);

    Route::get('/products/alerts/critical', [ProductController::class, 'criticalAlerts']);
    Route::apiResource('products', ProductController::class);
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('brands', BrandController::class);
});

use App\Http\Controllers\Api\CashRegisterController;

Route::prefix('shifts')->group(function () {
    Route::get('/', [CashShiftController::class, 'index']);
    Route::get('/current', [CashShiftController::class, 'current']);
    Route::post('/open', [CashShiftController::class, 'open']);
    Route::post('/{id}/close', [CashShiftController::class, 'close']);
});

Route::prefix('registers')->group(function () {
    // Lectura pública para inicializar la caja (Index maneja el leakage internamente)
    Route::get('/', [CashRegisterController::class, 'index']);

    // Admin/Premium routes
    Route::middleware(['addon:multi_caja'])->group(function () {
        Route::post('/', [CashRegisterController::class, 'store']);
        Route::put('/{id}', [CashRegisterController::class, 'update']);
        Route::delete('/{id}', [CashRegisterController::class, 'destroy']);
    });
});

use App\Http\Controllers\Api\TrashController;

Route::prefix('trash')->group(function () {
    Route::get('/{model}', [TrashController::class, 'index']);
    Route::post('/{model}/{id}/restore', [TrashController::class, 'restore']);
    Route::delete('/{model}/{id}/force', [TrashController::class, 'forceDelete']);
});
