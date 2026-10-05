<?php

use App\Constants\Permissions;
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
use App\Http\Controllers\Api\RubroController;
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
Route::get('/catalog/rubros', [RubroController::class, 'index']);
Route::get('/catalog/rubros/{rubro}', [RubroController::class, 'show']);
Route::get('/catalog/brands', [BrandController::class, 'index']);
// FIX A-2: GET /users era pública y exponía nombres y roles sin sesión.
// Ahora la lista de usuarios solo se puede obtener con un token válido.
// La ruta de lectura se consolida dentro del grupo session.validate (ver más abajo).
Route::apiResource('payment-methods', PaymentMethodController::class)->only(['index']);

// ══════════════════════════════════════════════════════════════════════════════
// RUTAS PROTEGIDAS — Requieren X-Session-Token válido (Single Active Session)
// ══════════════════════════════════════════════════════════════════════════════

Route::middleware(['session.validate'])->group(function () {

    // ── 1. CONFIGURACIÓN Y ADMINISTRACIÓN ─────────────────────────────
    Route::put('/settings', [SettingController::class, 'update'])
        ->middleware('permission.or.pin:' . Permissions::MANAGE_SETTINGS);
    Route::get('/settings/integrations', [SettingController::class, 'integrations'])
        ->middleware('permission.or.pin:' . Permissions::MANAGE_SETTINGS);
    Route::put('/settings/integrations', [SettingController::class, 'updateIntegrations'])
        ->middleware('permission.or.pin:' . Permissions::MANAGE_SETTINGS);
    Route::post('/settings/afip/certificates', [SettingController::class, 'uploadAfipCertificates'])
        ->middleware('permission.or.pin:' . Permissions::MANAGE_SETTINGS);
    Route::post('/settings/afip/upload-certificates', [SettingController::class, 'uploadAfipCertificates'])
        ->middleware('permission.or.pin:' . Permissions::MANAGE_SETTINGS);
    Route::post('/settings/logo', [SettingController::class, 'uploadLogo'])
        ->middleware('permission.or.pin:' . Permissions::MANAGE_SETTINGS);

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
    Route::post('/sales/{sale}/void', [SalesController::class, 'void'])
        ->middleware('permission.or.pin:' . Permissions::VOID_SALES);
    Route::put('/sales/{sale}/pay', [SalesController::class, 'pay'])
        ->middleware('permission.or.pin:' . Permissions::COLLECT_CUSTOMER_DEBT);
    Route::get('/sales/{sale}/ticket-pdf', [SalesController::class, 'ticketPdf']);
    Route::get('/sales/{sale}', [SalesController::class, 'show']);

    // ── Clientes: gestión completa y cuentas corrientes ──────────────
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::get('/customers/{customer}', [CustomerController::class, 'show']);
    Route::apiResource('customers', CustomerController::class)
        ->except(['index', 'show'])
        ->middleware('permission.or.pin:' . Permissions::MANAGE_CUSTOMERS);
    Route::get('/customers/{customer}/pending-sales', [CustomerController::class, 'getPendingSales'])
        ->middleware('permission.or.pin:' . Permissions::VIEW_CUSTOMERS_ACCOUNT);
    Route::post('/customers/{customer}/payments', [CustomerController::class, 'registerPayment'])
        ->middleware('permission.or.pin:' . Permissions::COLLECT_CUSTOMER_DEBT);

    // 💸 Módulo Movimientos de Caja y Gastos 💸
    Route::get('/cash-movements', [CashMovementController::class, 'index'])
        ->middleware('permission.or.pin:' . Permissions::VIEW_EXPENSES);
    Route::get('/cash-movements/export', [CashMovementController::class, 'export'])
        ->middleware('permission.or.pin:' . Permissions::VIEW_EXPENSES);
    Route::post('/cash-movements/upload', [CashMovementController::class, 'uploadAttachment'])
        ->middleware('permission.or.pin:' . Permissions::CREATE_EXPENSES);
    Route::post('/cash-movements', [CashMovementController::class, 'store'])
        ->middleware('permission.or.pin:' . Permissions::CREATE_EXPENSES);
    Route::delete('/cash-movements/{cash_movement}', [CashMovementController::class, 'destroy'])
        ->middleware('permission.or.pin:' . Permissions::DELETE_CASH_MOVEMENTS);

    Route::middleware(['feature:expenses'])->group(function () {
        Route::get('/expense-categories', [ExpenseCategoryController::class, 'index']);
        Route::apiResource('expense-categories', ExpenseCategoryController::class)
            ->except(['index'])
            ->middleware('permission.or.pin:' . Permissions::MANAGE_EXPENSE_CATEGORIES);
    });

    // ── Módulo Cartera de Cheques ────────────────────────────────────
    Route::middleware(['feature:checks'])->group(function () {
        Route::get('/third-party-checks', [ThirdPartyCheckController::class, 'index'])
            ->middleware('permission.or.pin:' . Permissions::VIEW_CHECKS);
        Route::patch('/third-party-checks/{check}/status', [ThirdPartyCheckController::class, 'updateStatus'])
            ->middleware('permission.or.pin:' . Permissions::ENDORSE_CHECKS);
    });

    // ── Módulo Proveedores ───────────────────────────────────────────
    Route::middleware(['feature:suppliers'])->group(function () {
        Route::get('/suppliers', [SupplierController::class, 'index'])
            ->middleware('permission.or.pin:' . Permissions::VIEW_SUPPLIERS);
        Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])
            ->middleware('permission.or.pin:' . Permissions::VIEW_SUPPLIERS);
        Route::get('/suppliers/{supplier}/current-account', [SupplierController::class, 'currentAccount'])
            ->middleware('permission.or.pin:' . Permissions::VIEW_SUPPLIERS);

        Route::post('/suppliers', [SupplierController::class, 'store'])
            ->middleware('permission.or.pin:' . Permissions::MANAGE_CATALOG);
        Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])
            ->middleware('permission.or.pin:' . Permissions::MANAGE_CATALOG);
        Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])
            ->middleware('permission.or.pin:' . Permissions::MANAGE_CATALOG);

        Route::post('/suppliers/{supplier}/invoices', [SupplierInvoiceController::class, 'store'])
            ->middleware('permission.or.pin:' . Permissions::CREATE_SUPPLIER_INVOICE);
        Route::post('/supplier-invoices/upload', [SupplierInvoiceController::class, 'uploadAttachment'])
            ->middleware('permission.or.pin:' . Permissions::CREATE_SUPPLIER_INVOICE);
    });

    // ── Catálogo: escritura (crear, editar, borrar productos) ────────
    Route::post('/catalog/products/bulk-delete', [CatalogController::class, 'bulkDelete'])
        ->middleware('permission.or.pin:' . Permissions::MANAGE_CATALOG);
    Route::post('/catalog/bulk-update', [CatalogController::class, 'bulkUpdate'])
        ->middleware('permission.or.pin:' . Permissions::MANAGE_CATALOG);
    Route::match(['put', 'post'], '/catalog/products/bulk-update', [CatalogController::class, 'bulkUpdate'])
        ->middleware('permission.or.pin:' . Permissions::MANAGE_CATALOG);
    Route::get('/catalog/bulk-price-history', [CatalogController::class, 'bulkPriceHistory'])
        ->middleware('permission.or.pin:' . Permissions::BULK_PRICE_UPDATE);
    Route::post('/catalog/bulk-price-history/{id}/revert', [CatalogController::class, 'bulkPriceRevert'])
        ->middleware('permission.or.pin:' . Permissions::BULK_PRICE_UPDATE);
    Route::post('/catalog/products/bulk-price-preview', [CatalogController::class, 'bulkPricePreview'])
        ->middleware('permission.or.pin:' . Permissions::BULK_PRICE_UPDATE);
    Route::put('/catalog/products/bulk-price-update', [CatalogController::class, 'bulkPriceUpdate'])
        ->middleware('permission.or.pin:' . Permissions::BULK_PRICE_UPDATE);
    Route::post('/catalog/products/{product}/adjust-stock', [StockController::class, 'adjust'])
        ->middleware('permission.or.pin:' . Permissions::ADJUST_STOCK);
    Route::post('/catalog/products/{product}/image', [ProductController::class, 'uploadImage'])
        ->middleware('permission.or.pin:' . Permissions::MANAGE_CATALOG);
    Route::delete('/catalog/products/{product}/image', [ProductController::class, 'deleteImage'])
        ->middleware('permission.or.pin:' . Permissions::MANAGE_CATALOG);
    Route::apiResource('catalog/products', ProductController::class)->except(['index', 'show'])
        ->middleware('permission.or.pin:' . Permissions::MANAGE_CATALOG);
    Route::apiResource('catalog/categories', CategoryController::class)->except(['index'])
        ->middleware('permission.or.pin:' . Permissions::MANAGE_CATALOG);
    Route::middleware(['feature:multi_rubro'])->group(function () {
        Route::apiResource('catalog/rubros', RubroController::class)->except(['index', 'show'])
            ->middleware('permission.or.pin:' . Permissions::MANAGE_CATALOG);
    });
    Route::apiResource('catalog/brands', BrandController::class)->except(['index'])
        ->middleware('permission.or.pin:' . Permissions::MANAGE_CATALOG);

    // ── Cajas (escritura: crear/editar/borrar) ───────────────────────
    Route::middleware(['feature:multi_caja'])->group(function () {
        Route::post('/registers', [CashRegisterController::class, 'store'])
            ->middleware('permission.or.pin:' . Permissions::MANAGE_SETTINGS);
        Route::put('/registers/{id}', [CashRegisterController::class, 'update'])
            ->middleware('permission.or.pin:' . Permissions::MANAGE_SETTINGS);
        Route::delete('/registers/{id}', [CashRegisterController::class, 'destroy'])
            ->middleware('permission.or.pin:' . Permissions::MANAGE_SETTINGS);
    });

    Route::apiResource('users', UserController::class)
        ->middleware('permission.or.pin:' . Permissions::MANAGE_USERS);

    Route::get('/shifts', [CashShiftController::class, 'index']);

    // ── Métodos de pago y papelera ───────────────────────────────────
    Route::apiResource('payment-methods', PaymentMethodController::class)->except(['index'])
        ->middleware('permission.or.pin:' . Permissions::MANAGE_SETTINGS);
    Route::prefix('trash')->group(function () {
        Route::get('/{model}', [TrashController::class, 'index'])
            ->middleware('permission.or.pin:' . Permissions::MANAGE_TRASH);
        Route::post('/{model}/{id}/restore', [TrashController::class, 'restore'])
            ->middleware('permission.or.pin:' . Permissions::MANAGE_TRASH);
        Route::delete('/{model}/{id}/force', [TrashController::class, 'forceDelete'])
            ->middleware('permission.or.pin:' . Permissions::MANAGE_TRASH);
    });

    // ── Auditoría (Kardex) ───────────────────────────────────────────
    Route::prefix('audit')->group(function () {
        Route::get('/stock', [StockController::class, 'kardex'])
            ->middleware('permission.or.pin:' . Permissions::VIEW_KARDEX);
    });

    // ── Módulo Presupuestos [hardware_store] ─────────────────────────
    Route::middleware(['feature:quotes'])->prefix('quotes')->group(function () {
        Route::get('/', [QuoteController::class, 'index'])
            ->middleware('permission.or.pin:' . Permissions::MANAGE_QUOTES);
        Route::post('/', [QuoteController::class, 'store'])
            ->middleware('permission.or.pin:' . Permissions::MANAGE_QUOTES);
        Route::get('/number/{number}', [QuoteController::class, 'showByNumber'])
            ->middleware('permission.or.pin:' . Permissions::MANAGE_QUOTES);
        Route::get('/{quote}', [QuoteController::class, 'show'])
            ->middleware('permission.or.pin:' . Permissions::MANAGE_QUOTES);
        Route::patch('/{quote}/status', [QuoteController::class, 'updateStatus'])
            ->middleware('permission.or.pin:' . Permissions::MANAGE_QUOTES);
        Route::put('/{quote}', [QuoteController::class, 'update'])
            ->middleware('permission.or.pin:' . Permissions::MANAGE_QUOTES);
        Route::delete('/{quote}', [QuoteController::class, 'destroy'])
            ->middleware('permission.or.pin:' . Permissions::MANAGE_QUOTES);
    });

    // ── Módulo de Reportes Gerenciales ───────────────────────────────
    Route::prefix('reports')->middleware('permission.or.pin:' . Permissions::VIEW_REPORTS)->group(function () {
        Route::get('/sales-by-category/export', [ReportController::class, 'exportProfitByCategory']);
        Route::get('/sales-by-category/pdf', [ReportController::class, 'exportPdfByCategory']);
        Route::get('/sales-by-category', [ReportController::class, 'profitByCategory']);
        Route::get('/sales-by-brand', [ReportController::class, 'profitByBrand']);
        Route::middleware(['feature:multi_rubro'])->group(function () {
            Route::get('/sales-by-rubro/export', [ReportController::class, 'exportProfitByRubro']);
            Route::get('/sales-by-rubro/pdf', [ReportController::class, 'exportPdfByRubro']);
            Route::get('/sales-by-rubro', [ReportController::class, 'profitByRubro']);
        });
        Route::get('/internal-consumption', [ReportController::class, 'internalConsumption']);
        Route::get('/monthly-balance/export', [ReportController::class, 'exportMonthlyBalanceExcel']);
        Route::get('/monthly-balance/pdf', [ReportController::class, 'exportMonthlyBalancePdf']);
        Route::get('/monthly-balance', [ReportController::class, 'monthlyBalance']);

        Route::middleware(['feature:expenses'])->group(function () {
            Route::get('/expenses-analysis/export', [ReportController::class, 'exportExpensesAnalysisExcel']);
            Route::get('/expenses-analysis/pdf', [ReportController::class, 'exportExpensesAnalysisPdf']);
            Route::get('/expenses-analysis', [ReportController::class, 'expensesAnalysis']);
        });
    });

    // ── Módulo de Inteligencia de Inventario ──────────────────────────
    Route::get('/inventory/alerts', [ProductController::class, 'inventoryAlerts']);

    // ── Módulo Logística (Remitos / Corralón) ──────────────────────────
    Route::prefix('delivery-notes')->middleware('permission.or.pin:' . Permissions::MANAGE_DELIVERY_NOTES)->group(function () {
        Route::get('/', [DeliveryNoteController::class, 'index']);
        Route::post('/from-sale/{saleId}', [DeliveryNoteController::class, 'generateFromSale']);
        Route::put('/{id}/deliver', [DeliveryNoteController::class, 'updateDelivery']);
    });
    // Mobile Scanner Module
    Route::post('/mobile/scan', [MobileScannerController::class, 'scan']);
    Route::post('/mobile/print-label', [MobileScannerController::class, 'printLabel']);
});
