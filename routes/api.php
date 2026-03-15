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

// Auth Routes could go here..

Route::get('/settings', [SettingController::class, 'index']);

Route::prefix('pos')->group(function () {
    Route::get('/products/search', [PosController::class, 'searchProducts']);
    Route::post('/sales', [PosController::class, 'processSale']);
});

Route::prefix('catalog')->group(function () {
    Route::put('/products/bulk-price-update', [CatalogController::class, 'bulkPriceUpdate']);
    
    Route::apiResource('products', ProductController::class);
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('brands', BrandController::class);
});

Route::prefix('cash-register')->group(function () {
    Route::get('/current', [CashRegisterController::class, 'current']);
    Route::post('/open', [CashRegisterController::class, 'open']);
    Route::post('/close', [CashRegisterController::class, 'close']);
});
