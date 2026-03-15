<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PosController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\CashRegisterController;

use App\Http\Controllers\Api\AuthController;

Route::prefix('auth')->group(function () {
    Route::post('/verify-pin', [AuthController::class, 'verifyPin']);
});

Route::apiResource('users', \App\Http\Controllers\Api\UserController::class);


Route::get('/settings', [SettingController::class, 'index']);
Route::put('/settings', [SettingController::class, 'update']);

use App\Http\Controllers\Api\SalesController;

Route::prefix('pos')->group(function () {
    Route::get('/products/search', [PosController::class, 'searchProducts']);
    Route::post('/sales', [PosController::class, 'processSale']);
});

Route::get('/sales', [SalesController::class, 'index']);
Route::post('/sales/{sale}/void', [SalesController::class, 'void']);

use App\Http\Controllers\Api\StockController;

Route::prefix('catalog')->group(function () {
    Route::put('/products/bulk-price-update', [CatalogController::class, 'bulkPriceUpdate']);
    Route::post('/products/{product}/adjust-stock', [StockController::class, 'adjust']);

    Route::apiResource('products', ProductController::class);
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('brands', BrandController::class);
});

Route::prefix('cash-register')->group(function () {
    Route::get('/shifts', [CashRegisterController::class, 'index']);
    Route::get('/current', [CashRegisterController::class, 'current']);
    Route::post('/open', [CashRegisterController::class, 'open']);
    Route::post('/close', [CashRegisterController::class, 'close']);
});
