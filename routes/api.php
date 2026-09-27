<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\CashRegisterController;
use App\Http\Controllers\Api\CashShiftController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\PosController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\SalesController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\TrashController;
use App\Http\Controllers\DeliveryNoteController;
use Illuminate\Support\Facades\Route;

// ══════════════════════════════════════════════════════════════════════════════
// RUTAS PÚBLICAS — No requieren sesión activa
// ══════════════════════════════════════════════════════════════════════════════

Route::prefix('auth')->group(function () {
    // Login completo: valida PIN, emite session_token, invalida sesión anterior
    Route::post('/verify-pin', [AuthController::class, 'verifyPin'])->middleware('throttle:10,1');

    // Autorización puntual (AdminPinDialog): valida PIN SIN emitir token ni tocar sesiones
    Route::post('/authorize-pin', [AuthController::class, 'authorizePin'])->middleware('throttle:10,1');

    // Validación silenciosa del token al arranque de la app (Crash Recovery)
    // Pública para que funcione antes del primer login y sin middleware de sesión.
    Route::get('/me', [AuthController::class, 'me'])->middleware('throttle:30,1');

    // Logout: requiere el token actual para poder nullificarlo
    Route::post('/logout', [AuthController::class, 'logout']);
});

// Lectura de configuración pública — necesaria en el arranque de la app ANTES del login
Route::get('/settings', [SettingController::class, 'index']);
// Escritura de licencia pública — se necesita sin sesión para activar/sincronizar licencias
Route::post('/settings/license', [SettingController::class, 'updateLicense']);
Route::post('/settings/license/sync', [SettingController::class, 'syncLicense']);

use App\Http\Controllers\Api\CashMovementController;
use App\Http\Controllers\Api\ExpenseCategoryController;
use App\Http\Controllers\Api\MobileScannerController;
use App\Http\Controllers\Api\PaymentMethodController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\SupplierInvoiceController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\ThirdPartyCheckController;
use App\Http\Controllers\Api\UserController;

// Endpoint de rescate de migraciones OTA (silencioso)
Route::match(['get', 'post'], '/system/rescue-migrate', [SystemController::class, 'rescueMigrate']);
Route::get('/version-check', [SystemController::class, 'versionCheck']);

// Verificación de turno activo (necesaria antes del login para decidir ruta inicial)
Route::prefix('shifts')->group(function () {
    // /current sigue siendo pública: necesaria antes del login para decidir la ruta inicial.
    Route::get('/current', [CashShiftController::class, 'current']);
    // /index (historial completo) se mueve al grupo protegido (ver más abajo).
});

// Lista de cajas disponibles (necesaria en CashRegisterScreen antes de login)
Route::get('/registers', [CashRegisterController::class, 'index']);

// Búsqueda de productos POS (usada antes de confirmar la venta)
Route::get('/pos/products/search', [PosController::class, 'searchProducts']);

// Lectura de catálogo, clientes e historial (pantallas de solo lectura)
Route::get('/catalog/products/alerts/critical', [ProductController::class, 'criticalAlerts']);
Route::get('/catalog/products/stock', [ProductController::class, 'stockBulk']);
Route::apiResource('catalog/products', ProductController::class)->only(['index', 'show']);
Route::get('/catalog/categories', [CategoryController::class, 'index']);
Route::get('/catalog/brands', [BrandController::class, 'index']);
// FIX A-2: GET /users era pública y exponía nombres y roles sin sesión.
// Ahora la lista de usuarios solo se puede obtener con un token válido.
// La ruta de lectura se consolida dentro del grupo session.validate (ver más abajo).
Route::apiResource('payment-methods', PaymentMethodController::class)->only(['index']);

// ══════════════════════════════════════════════════════════════════════════════
// RUTAS PROTEGIDAS — Requieren X-Session-Token válido (Single Active Session)
// ══════════════════════════════════════════════════════════════════════════════

Route::middleware(['session.validate'])->group(function () {

    // ── Configuración del negocio (ESCRITURA PROTEGIDA) ───────────────
    Route::middleware(['role.admin'])->group(function () {
        Route::put('/settings', [SettingController::class, 'update']);
    });

    // ── POS: Procesar venta (CRÍTICO) ────────────────────────────────
    Route::post('/pos/sales', [PosController::class, 'processSale']);

    // ── Turnos de caja (CRÍTICO) ─────────────────────────────────────
    Route::prefix('shifts')->group(function () {
        Route::post('/open', [CashShiftController::class, 'open']);
        Route::post('/{id}/close', [CashShiftController::class, 'close']);
    });

    // ── Ventas: listado, anulación y pago de cuentas corrientes ──────
    Route::get('/sales', [SalesController::class, 'index']);
    Route::get('/sales/pending', [SalesController::class, 'pending']);
    Route::post('/sales/{sale}/void', [SalesController::class, 'void']);
    Route::put('/sales/{sale}/pay', [SalesController::class, 'pay']);
    Route::get('/sales/{sale}/ticket-pdf', [SalesController::class, 'ticketPdf']);
    Route::get('/sales/{sale}', [SalesController::class, 'show']);

    // ── Clientes: gestión completa y cuentas corrientes ──────────────
    Route::apiResource('customers', CustomerController::class);
    Route::post('/customers/{customer}/payments', [CustomerController::class, 'registerPayment']);
    Route::get('/customers/{customer}/pending-sales', [CustomerController::class, 'getPendingSales']);

    // 💸 Módulo Movimientos de Caja y Gastos 💸
    Route::get('cash-movements/export', [CashMovementController::class, 'export']);
    Route::post('cash-movements/upload', [CashMovementController::class, 'uploadAttachment']);
    Route::apiResource('cash-movements', CashMovementController::class)->only(['index', 'store']);
    Route::middleware(['role.or.pin'])->group(function () {
        Route::delete('/cash-movements/{cash_movement}', [CashMovementController::class, 'destroy']);
    });
    Route::middleware(['feature:expenses', 'role.admin'])->group(function () {
        Route::apiResource('expense-categories', ExpenseCategoryController::class);
    });

    // ── Módulo Cartera de Cheques ────────────────────────────────────
    Route::middleware(['feature:checks'])->group(function () {
        Route::get('/third-party-checks', [ThirdPartyCheckController::class, 'index']);
        Route::patch('/third-party-checks/{check}/status', [ThirdPartyCheckController::class, 'updateStatus']);
    });

    // ── Módulo Proveedores ───────────────────────────────────────────
    Route::middleware(['feature:suppliers'])->group(function () {
        Route::apiResource('suppliers', SupplierController::class)->only(['index', 'show']);
        Route::middleware(['role.or.pin'])->group(function () {
            Route::apiResource('suppliers', SupplierController::class)->except(['index', 'show']);
            Route::get('suppliers/{supplier}/current-account', [SupplierController::class, 'currentAccount']);
            Route::post('suppliers/{supplier}/invoices', [SupplierInvoiceController::class, 'store']);
            Route::post('supplier-invoices/upload', [SupplierInvoiceController::class, 'uploadAttachment']);
        });
    });

    // ── Catálogo: escritura (crear, editar, borrar productos) ────────
    Route::post('/catalog/products/bulk-delete', [CatalogController::class, 'bulkDelete']);
    Route::put('/catalog/products/bulk-update', [CatalogController::class, 'bulkUpdate']);
    Route::get('/catalog/bulk-price-history', [CatalogController::class, 'bulkPriceHistory']);
    Route::post('/catalog/bulk-price-history/{id}/revert', [CatalogController::class, 'bulkPriceRevert']);
    Route::post('/catalog/products/bulk-price-preview', [CatalogController::class, 'bulkPricePreview']);
    Route::put('/catalog/products/bulk-price-update', [CatalogController::class, 'bulkPriceUpdate']);
    Route::post('/catalog/products/{product}/adjust-stock', [StockController::class, 'adjust']);
    Route::apiResource('catalog/products', ProductController::class)->except(['index', 'show']);
    Route::apiResource('catalog/categories', CategoryController::class)->except(['index']);
    Route::apiResource('catalog/brands', BrandController::class)->except(['index']);

    // ── Cajas (escritura: crear/editar/borrar) ───────────────────────
    Route::middleware(['feature:multi_caja', 'role.admin'])->group(function () {
        Route::post('/registers', [CashRegisterController::class, 'store']);
        Route::put('/registers/{id}', [CashRegisterController::class, 'update']);
        Route::delete('/registers/{id}', [CashRegisterController::class, 'destroy']);
    });

    // FIX A-2: Usuarios — lectura Y escritura ahora protegidas por sesión activa.
    Route::middleware(['role.admin'])->group(function () {
        Route::apiResource('users', UserController::class);
    });

    // FIX S-1: Historial de turnos protegido — no puede ser consultado sin sesión activa.
    // Solo /shifts/current sigue siendo pública (necesaria pre-login).
    Route::get('/shifts', [CashShiftController::class, 'index']);

    // ── Métodos de pago y papelera (admin) ───────────────────────────
    Route::apiResource('payment-methods', PaymentMethodController::class)->except(['index']);
    Route::middleware(['role.or.pin'])->prefix('trash')->group(function () {
        Route::get('/{model}', [TrashController::class, 'index']);
        Route::post('/{model}/{id}/restore', [TrashController::class, 'restore']);
        Route::delete('/{model}/{id}/force', [TrashController::class, 'forceDelete']);
    });

    // ── Auditoría (Kardex) ───────────────────────────────────────────
    Route::middleware(['role.admin'])->prefix('audit')->group(function () {
        Route::get('/stock', [StockController::class, 'kardex']);
    });

    // ── Módulo Presupuestos [hardware_store] ─────────────────────────
    Route::middleware(['feature:quotes'])->prefix('quotes')->group(function () {
        Route::get('/', [QuoteController::class, 'index']);
        Route::post('/', [QuoteController::class, 'store']);
        Route::get('/number/{number}', [QuoteController::class, 'showByNumber']);
        Route::get('/{quote}', [QuoteController::class, 'show']);
        Route::patch('/{quote}/status', [QuoteController::class, 'updateStatus']);
        Route::put('/{quote}', [QuoteController::class, 'update']);
        Route::delete('/{quote}', [QuoteController::class, 'destroy']);
    });

    // ── Módulo de Reportes Gerenciales (ADMIN) ───────────────────────
    Route::middleware(['role.admin'])->group(function () {
        Route::get('/reports/sales-by-category/export', [ReportController::class, 'exportProfitByCategory']);
        Route::get('/reports/sales-by-category/pdf', [ReportController::class, 'exportPdfByCategory']);
        Route::get('/reports/sales-by-category', [ReportController::class, 'profitByCategory']);
        Route::get('/reports/sales-by-brand', [ReportController::class, 'profitByBrand']);
        Route::get('/reports/internal-consumption', [ReportController::class, 'internalConsumption']);
        Route::get('/reports/monthly-balance/export', [ReportController::class, 'exportMonthlyBalanceExcel']);
        Route::get('/reports/monthly-balance/pdf', [ReportController::class, 'exportMonthlyBalancePdf']);
        Route::get('/reports/monthly-balance', [ReportController::class, 'monthlyBalance']);

        Route::middleware(['feature:expenses'])->group(function () {
            Route::get('/reports/expenses-analysis/export', [ReportController::class, 'exportExpensesAnalysisExcel']);
            Route::get('/reports/expenses-analysis/pdf', [ReportController::class, 'exportExpensesAnalysisPdf']);
            Route::get('/reports/expenses-analysis', [ReportController::class, 'expensesAnalysis']);
        });
    });

    // ── Módulo de Inteligencia de Inventario ──────────────────────────
    Route::get('/inventory/alerts', [ProductController::class, 'inventoryAlerts']);

    // ── Módulo Logística (Remitos / Corralón) ──────────────────────────
    Route::prefix('delivery-notes')->group(function () {
        Route::get('/', [DeliveryNoteController::class, 'index']);
        Route::post('/from-sale/{saleId}', [DeliveryNoteController::class, 'generateFromSale']);
        Route::put('/{id}/deliver', [DeliveryNoteController::class, 'updateDelivery']);
    });
    // Mobile Scanner Module
    Route::post('/mobile/scan', [MobileScannerController::class, 'scan']);
    Route::post('/mobile/print-label', [MobileScannerController::class, 'printLabel']);
});
