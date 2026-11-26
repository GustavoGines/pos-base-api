<?php

namespace App\Services\Demo;

use App\Http\Controllers\Api\SystemController;
use App\Models\BusinessSetting;
use App\Models\CashRegister;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Rubro;
use App\Models\User;
use App\Services\BarcodeService;
use App\Services\BatchStockService;
use App\Services\LicenseSyncService;
use App\Services\ReportCacheService;
use App\Services\StockService;
use App\Services\SystemRubroSyncService;
use Carbon\Carbon;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\PaymentMethodSeeder;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Gestiona el ciclo de vida del Modo Exploración Demo:
 *  - Diagnóstico de estado (status)
 *  - Generación dinámica silenciosa y rápida acorde al vertical de la licencia (seed)
 *  - Cierre de invitación inicial (dismissOnboarding)
 *  - Limpieza atómica blindada con Cuádruple Candado + Snapshot PDO previo (purgeDemoData)
 */
class DemoEnvironmentService
{
    public const LOCK_KEY = 'pos_demo_env_lock';

    /**
     * Orden topológico estricto Hijo -> Padre para borrado atómico sin desactivar FKs.
     *
     * @var array<int, string>
     */
    private const PURGE_TABLES_CHILD_FIRST = [
        'sale_item_batch_allocations',
        'production_order_consumptions',
        'production_orders',
        'delivery_note_items',
        'delivery_notes',
        'stock_movements',
        'cash_movements',
        'third_party_checks',
        'mp_transactions',
        'mp_webhooks',
        'customer_transactions',
        'sale_payments',
        'sale_items',
        'sales',
        'cash_shifts',
        'supplier_invoice_audits',
        'supplier_invoice_items',
        'product_batches',
        'supplier_invoices',
        'quote_items',
        'quotes',
        'offer_products',
        'offers',
        'product_recipe_items',
        'product_combos',
        'product_price_tiers',
        'bulk_price_history_items',
        'bulk_price_histories',
        'ecommerce_sync_logs',
        'ecommerce_product_mappings',
        'products',
        'categories',
        // `rubros` se limpia selectivamente (WHERE is_system = false)
        'brands',
        'suppliers',
        'customers',
        'expense_categories',
    ];

    public function getStatus(): array
    {
        $settings = BusinessSetting::whereIn('key', [
            'demo_mode_active',
            'demo_seeded_at',
            'demo_onboarding_dismissed',
            'demo_max_seeded_product_id',
            'license_is_trial',
            'license_business_type',
        ])->pluck('value', 'key')->toArray();

        $isDemoActive = ($settings['demo_mode_active'] ?? '0') === '1';
        $isTrial = ($settings['license_is_trial'] ?? '0') === '1';
        $maxSeededProductId = (int) ($settings['demo_max_seeded_product_id'] ?? 0);

        $productsCount = (int) DB::table('products')->whereNull('deleted_at')->count();
        $salesCount = (int) DB::table('sales')->count();
        $customersCount = (int) DB::table('customers')->whereNull('deleted_at')->count();
        $suppliersCount = (int) DB::table('suppliers')->whereNull('deleted_at')->count();
        $quotesCount = Schema::hasTable('quotes') ? (int) DB::table('quotes')->count() : 0;

        $hasAnyRecords = DB::table('products')->exists()
            || DB::table('sales')->exists()
            || DB::table('customers')->exists()
            || DB::table('suppliers')->exists()
            || DB::table('electronic_invoices')->exists();

        $hasFiscalBlock = DB::table('electronic_invoices')->exists()
            || DB::table('sales')->whereIn('invoice_status', ['invoiced', 'pending'])->exists();

        $userAddedProducts = ($isDemoActive && $maxSeededProductId > 0)
            ? (int) DB::table('products')->where('id', '>', $maxSeededProductId)->whereNull('deleted_at')->count()
            : 0;

        return [
            'demo_mode_active' => $isDemoActive,
            'is_trial' => $isTrial,
            'is_fresh_empty_store' => $isTrial && ! $isDemoActive && ! $hasAnyRecords,
            'demo_onboarding_dismissed' => ($settings['demo_onboarding_dismissed'] ?? '0') === '1',
            'demo_seeded_at' => $settings['demo_seeded_at'] ?? null,
            'business_type' => (string) ($settings['license_business_type'] ?? 'retail'),
            'has_fiscal_block' => $hasFiscalBlock,
            'counts' => [
                'products' => $productsCount,
                'sales' => $salesCount,
                'customers' => $customersCount,
                'suppliers' => $suppliersCount,
                'quotes' => $quotesCount,
                'user_added_products_after_seed' => $userAddedProducts,
            ],
        ];
    }

    public function dismissOnboarding(): void
    {
        $isTrial = BusinessSetting::where('key', 'license_is_trial')->value('value') === '1';
        if (! $isTrial) {
            throw new DomainException('DEMO_ONLY_FOR_TRIALS:Solo las licencias en período de prueba admiten omitir el asistente demo.', 403);
        }

        BusinessSetting::updateOrCreate(
            ['key' => 'demo_onboarding_dismissed'],
            ['value' => '1']
        );
    }

    public function seed(User $actor): array
    {
        if (! $actor->isAdmin()) {
            throw new DomainException('ADMIN_ONLY:Solo un usuario Administrador puede cargar los datos de ejemplo.', 403);
        }

        $lock = Cache::lock(self::LOCK_KEY, 60);
        if (! $lock->get()) {
            throw new DomainException('DEMO_OPERATION_IN_PROGRESS:Hay otra operación de entorno demo en curso. Reintentá en unos segundos.', 409);
        }

        try {
            $isTrial = BusinessSetting::where('key', 'license_is_trial')->value('value') === '1';
            if (! $isTrial) {
                throw new DomainException('DEMO_ONLY_FOR_TRIALS:La carga de datos de ejemplo solo está habilitada en licencias de prueba (Demo).', 403);
            }

            if (BusinessSetting::isDemoModeActive()) {
                throw new DomainException('DEMO_ALREADY_ACTIVE:El Modo Exploración con datos de ejemplo ya se encuentra activo.', 409);
            }

            if ($this->storeHasOperationalData()) {
                throw new DomainException('STORE_NOT_EMPTY:El comercio ya posee productos, clientes, proveedores o ventas registradas.', 409);
            }

            $summary = Model::withoutEvents(function () use ($actor) {
                return DB::transaction(function () use ($actor) {
                    return $this->runSeedTransaction($actor);
                });
            });

            try {
                app(StockService::class)->recalculatePopularityCounts();
            } catch (\Throwable) {
            }
            ReportCacheService::flush();
            Cache::forget(LicenseSyncService::DB_ACTIVITY_CACHE_KEY);

            return $summary;
        } finally {
            optional($lock)->release();
        }
    }

    public function purgeDemoData(User $actor, string $adminPin, string $confirmationText, string $ipAddress): array
    {
        // Candado 1: Estado de origen estricto (independiente de si la licencia ya fue convertida a pago)
        if (! BusinessSetting::isDemoModeActive()) {
            throw new DomainException('DEMO_MODE_NOT_ACTIVE:El sistema no está en Modo Datos de Ejemplo. Por seguridad, no se permite limpiar datos de un comercio real.', 403);
        }

        // Candado 2: Solo rol Admin + frase de confirmación + PIN verificado con throttle
        if (! $actor->isAdmin()) {
            throw new DomainException('ADMIN_ONLY:Solo un usuario Administrador puede limpiar los datos de ejemplo.', 403);
        }

        if (strtoupper(trim($confirmationText)) !== 'LIMPIAR') {
            throw new DomainException('INVALID_CONFIRMATION_TEXT:Debés escribir exactamente la palabra LIMPIAR para confirmar el reinicio.', 422);
        }

        $throttleKey = 'demo_reset_pin:'.$actor->id.':'.$ipAddress;
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            throw new DomainException("PIN_LOCKED_TEMPORARILY:Demasiados intentos erróneos de PIN. Operación bloqueada por {$seconds} segundos.", 429);
        }

        $pinMatched = false;
        $admins = User::withoutGlobalScope('visible')
            ->where('role', 'admin')
            ->whereNotNull('pin')
            ->get();
        foreach ($admins as $admin) {
            if (Hash::check(trim($adminPin), (string) $admin->pin)) {
                $pinMatched = true;
                break;
            }
        }

        if (! $pinMatched) {
            RateLimiter::hit($throttleKey, 300);
            throw new DomainException('INVALID_ADMIN_PIN:El PIN de administrador ingresado es incorrecto.', 403);
        }
        RateLimiter::clear($throttleKey);

        // Candado 3: Escudo fiscal absoluto
        if ($this->hasFiscalBlock()) {
            throw new DomainException('DEMO_RESET_BLOCKED_FISCAL_DATA:Se detectaron comprobantes fiscales electrónicos o pendientes en ARCA. Por seguridad tributaria no es posible limpiar la base automáticamente.', 409);
        }

        $lock = Cache::lock(self::LOCK_KEY, 60);
        if (! $lock->get()) {
            throw new DomainException('DEMO_OPERATION_IN_PROGRESS:Hay otra operación de entorno demo en curso. Reintentá en unos segundos.', 409);
        }

        try {
            if (! BusinessSetting::isDemoModeActive()) {
                throw new DomainException('DEMO_MODE_NOT_ACTIVE:El modo de ejemplo ya fue limpiado.', 403);
            }
            if ($this->hasFiscalBlock()) {
                throw new DomainException('DEMO_RESET_BLOCKED_FISCAL_DATA:Se detectaron comprobantes fiscales electrónicos en ARCA.', 409);
            }

            // Candado 4: Snapshot automático previo usando PDO nativo (sin depender de mysqldump)
            $snapshotFilename = $this->createPreResetSafetySnapshot();

            // Recolectar rutas de archivos de productos/comprobantes antes de borrar (jamás logo ni AFIP)
            $publicFilesToDelete = $this->collectDemoPublicFiles();

            Model::withoutEvents(function () {
                DB::transaction(function () {
                    foreach (self::PURGE_TABLES_CHILD_FIRST as $table) {
                        if ($table === 'brands') {
                            if (Schema::hasTable('rubros')) {
                                DB::table('rubros')->where('is_system', false)->delete();
                            }
                        }

                        if (Schema::hasTable($table)) {
                            DB::table($table)->delete();
                        }
                    }

                    // Re-hidratar catálogos base del sistema
                    $primaryRegister = CashRegister::withTrashed()->find(1);
                    if ($primaryRegister) {
                        if ($primaryRegister->trashed()) {
                            $primaryRegister->restore();
                        }
                        if (! $primaryRegister->is_active) {
                            $primaryRegister->update(['is_active' => true]);
                        }
                    } else {
                        CashRegister::create(['id' => 1, 'name' => 'Caja Principal', 'is_active' => true]);
                    }

                    (new PaymentMethodSeeder)->run();
                    (new ExpenseCategorySeeder)->run();

                    $businessType = (string) (BusinessSetting::where('key', 'license_business_type')->value('value') ?? 'retail');
                    $secondaryTypes = BusinessSetting::getSecondaryBusinessTypes();
                    app(SystemRubroSyncService::class)->sync($businessType, $secondaryTypes);

                    // Restaurar ajustes temporales del demo sin tocar licencia, usuarios, impresoras ni HWM
                    $prevTiers = BusinessSetting::where('key', 'demo_prev_enable_advanced_price_tiers')->value('value');
                    if ($prevTiers !== null) {
                        if ($prevTiers === '__NULL__') {
                            BusinessSetting::where('key', 'enable_advanced_price_tiers')->delete();
                        } else {
                            BusinessSetting::updateOrCreate(
                                ['key' => 'enable_advanced_price_tiers'],
                                ['value' => $prevTiers]
                            );
                        }
                    }

                    BusinessSetting::updateOrCreate(['key' => 'demo_mode_active'], ['value' => '0']);
                    BusinessSetting::updateOrCreate(['key' => 'demo_onboarding_dismissed'], ['value' => '1']);
                    BusinessSetting::whereIn('key', [
                        'demo_seeded_at',
                        'demo_max_seeded_product_id',
                        'demo_prev_enable_advanced_price_tiers',
                    ])->delete();

                    // Reiniciar anchors de IDs sin retroceder jamás license_max_observed_at ni last_license_check
                    BusinessSetting::updateOrCreate(['key' => 'license_clock_anchor_sale_id'], ['value' => '0']);
                    BusinessSetting::updateOrCreate(['key' => 'license_clock_anchor_shift_id'], ['value' => '0']);

                    app(StockService::class)->resetPopularityCounts();
                });
            });

            foreach ($publicFilesToDelete as $relPath) {
                try {
                    if ($relPath !== '' && Storage::disk('public')->exists($relPath)) {
                        Storage::disk('public')->delete($relPath);
                    }
                } catch (\Throwable) {
                }
            }

            ReportCacheService::flush();
            BatchStockService::flushSchemaCache();
            Cache::forget(LicenseSyncService::DB_ACTIVITY_CACHE_KEY);

            return [
                'cleaned' => true,
                'snapshot_file' => $snapshotFilename,
            ];
        } finally {
            optional($lock)->release();
        }
    }

    private function storeHasOperationalData(): bool
    {
        return DB::table('products')->exists()
            || DB::table('sales')->exists()
            || DB::table('customers')->exists()
            || DB::table('suppliers')->exists()
            || DB::table('electronic_invoices')->exists();
    }

    private function hasFiscalBlock(): bool
    {
        return DB::table('electronic_invoices')->exists()
            || DB::table('sales')->whereIn('invoice_status', ['invoiced', 'pending'])->exists();
    }

    private function createPreResetSafetySnapshot(): ?string
    {
        $backupDir = storage_path('app/private/backups');
        if (! is_dir($backupDir) && ! @mkdir($backupDir, 0755, true)) {
            throw new \RuntimeException('No se pudo preparar el directorio de respaldo preventivo antes de limpiar.');
        }

        $filename = 'pre_demo_reset_'.now()->format('Ymd_His').'_'.Str::random(6).'.sql';
        $fullPath = $backupDir.DIRECTORY_SEPARATOR.$filename;

        app(SystemController::class)->writeSqlDump($fullPath);

        // Conservar únicamente los últimos 2 snapshots preventivos
        $existing = glob($backupDir.DIRECTORY_SEPARATOR.'pre_demo_reset_*.sql') ?: [];
        if (count($existing) > 2) {
            usort($existing, fn (string $a, string $b) => filemtime($a) <=> filemtime($b));
            $toDelete = array_slice($existing, 0, count($existing) - 2);
            foreach ($toDelete as $oldFile) {
                @unlink($oldFile);
            }
        }

        return $filename;
    }

    /**
     * @return array<int, string>
     */
    private function collectDemoPublicFiles(): array
    {
        $paths = [];
        foreach (DB::table('products')->whereNotNull('image_path')->pluck('image_path') as $p) {
            $paths[] = (string) $p;
        }
        if (Schema::hasTable('supplier_invoices')) {
            foreach (DB::table('supplier_invoices')->whereNotNull('receipt_file_url')->pluck('receipt_file_url') as $p) {
                $clean = ltrim(str_replace('/storage/', '', (string) $p), '/');
                if ($clean !== '' && ! str_starts_with($clean, 'http')) {
                    $paths[] = $clean;
                }
            }
        }
        if (Schema::hasTable('cash_movements')) {
            foreach (DB::table('cash_movements')->whereNotNull('receipt_file_url')->pluck('receipt_file_url') as $p) {
                $clean = ltrim(str_replace('/storage/', '', (string) $p), '/');
                if ($clean !== '' && ! str_starts_with($clean, 'http')) {
                    $paths[] = $clean;
                }
            }
        }

        return array_values(array_unique(array_filter($paths)));
    }

    private function runSeedTransaction(User $actor): array
    {
        $now = now();
        $nowStr = $now->format('Y-m-d H:i:s');

        // 1. Asegurar registros base
        $register = CashRegister::withTrashed()->find(1);
        if ($register) {
            if ($register->trashed()) {
                $register->restore();
            }
            if (! $register->is_active) {
                $register->update(['is_active' => true]);
            }
        } else {
            CashRegister::create(['id' => 1, 'name' => 'Caja Principal', 'is_active' => true]);
        }

        (new PaymentMethodSeeder)->run();
        PaymentMethod::firstOrCreate(
            ['code' => 'cheque'],
            [
                'name' => 'Cheque de Terceros',
                'surcharge_type' => 'none',
                'surcharge_value' => 0.0,
                'is_cash' => false,
                'is_active' => true,
                'sort_order' => 50,
            ]
        );
        (new ExpenseCategorySeeder)->run();

        $businessType = (string) (BusinessSetting::where('key', 'license_business_type')->value('value') ?? 'retail');
        if (! in_array($businessType, Rubro::VALID_VERTICAL_TYPES, true)) {
            $businessType = 'retail';
        }
        $secondaryTypes = BusinessSetting::getSecondaryBusinessTypes();
        app(SystemRubroSyncService::class)->sync($businessType, $secondaryTypes);

        $systemRubroId = (int) (Rubro::where('is_system', true)->value('id') ?? Rubro::value('id'));
        $catalog = DemoVerticalCatalogs::forVertical($businessType);

        // 2. Categorías y Marcas
        $categoryIds = [];
        foreach ($catalog['categories'] as $cat) {
            $categoryIds[$cat['key']] = DB::table('categories')->insertGetId([
                'rubro_id' => $systemRubroId,
                'name' => $cat['name'],
                'description' => $cat['description'],
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ]);
        }

        $brandIds = [];
        foreach ($catalog['brands'] as $brandName) {
            $brandIds[$brandName] = DB::table('brands')->insertGetId([
                'name' => $brandName,
                'description' => null,
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ]);
        }

        // 3. Proveedores
        $supplierIds = [];
        foreach ($catalog['suppliers'] as $idx => $sup) {
            $supplierIds[] = DB::table('suppliers')->insertGetId([
                'name' => $sup['name'],
                'cuit' => $sup['cuit'],
                'tax_category' => $sup['tax_category'],
                'contact_name' => $sup['contact_name'],
                'phone' => $sup['phone'],
                'email' => $sup['email'],
                'address' => $sup['address'],
                'balance' => $idx === 0 ? 185000.00 : ($idx === 1 ? 72000.00 : 0.00),
                'is_active' => true,
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
                'deleted_at' => null,
            ]);
        }

        // 4. Productos del vertical principal
        $barcodeService = app(BarcodeService::class);
        $hasLotSchema = BatchStockService::hasLotTrackingSchema();
        $batchesEnabled = BusinessSetting::isBatchExpirationEnabledGlobally();
        $hasExtendedCols = Product::hasExtendedCatalogColumns();

        $productRecords = [];
        $productByCode = [];
        $seq = 1;

        foreach ($catalog['products'] as $item) {
            $plu = str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
            $seq++;
            $isService = (bool) ($item['is_service'] ?? false);
            $isWeight = (bool) ($item['weight'] ?? false);
            $barcode = ($isWeight || $isService) ? null : $barcodeService->generateInternalEan13($plu);
            $trackBatches = $hasLotSchema && $batchesEnabled && ! $isService && ! empty($item['track_batches']);

            $expDate = null;
            if (! empty($item['exp_days'])) {
                $expDate = $now->copy()->addDays((int) $item['exp_days'])->format('Y-m-d');
            }

            $payload = [
                'name' => $item['name'],
                'barcode' => $barcode,
                'internal_code' => $item['code'],
                'cost_price' => round((float) $item['cost'], 2),
                'selling_price' => round((float) $item['price'], 2),
                'price_wholesale' => round((float) $item['price'] * 0.88, 2),
                'price_card' => round((float) $item['price'] * 1.15, 2),
                'iva_rate' => (float) ($item['iva'] ?? 21.00),
                'stock' => (float) $item['stock'],
                'min_stock' => (float) $item['min_stock'],
                'active' => true,
                'needs_catalog_review' => false,
                'is_combo' => false,
                'is_service' => $isService,
                'is_sold_by_weight' => $isWeight,
                'sales_count' => 0,
                'vencimiento_dias' => ! empty($item['exp_days']) && (int) $item['exp_days'] > 0 ? (int) $item['exp_days'] : null,
                'expiration_date' => $expDate,
                'expiry_alert_days' => 15,
                'unit_type' => (string) ($item['unit_type'] ?? 'un'),
                'calc_config' => isset($item['calc_config']) ? json_encode($item['calc_config'], JSON_UNESCAPED_UNICODE) : null,
                'category_id' => $categoryIds[$item['cat']] ?? reset($categoryIds),
                'brand_id' => $brandIds[$item['brand']] ?? reset($brandIds),
                'supplier_id' => $supplierIds[0],
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
                'deleted_at' => null,
            ];

            if ($hasLotSchema) {
                $payload['track_batches'] = $trackBatches;
                $payload['lot_deduction_policy'] = 'fefo';
                $payload['allow_sell_expired'] = false;
            }
            if ($hasExtendedCols) {
                $payload['supplier_sku'] = $item['sku'] ?? $item['code'];
                $payload['storage_location'] = $item['loc'] ?? null;
                $payload['technical_spec'] = $item['spec'] ?? null;
                $payload['purchase_package_name'] = $item['pkg'] ?? null;
                $payload['conversion_factor'] = $item['conv'] ?? null;
            }

            $pid = DB::table('products')->insertGetId($payload);
            $record = array_merge($item, [
                'id' => $pid,
                'track_batches' => $trackBatches,
                'is_service' => $isService,
            ]);
            $productRecords[] = $record;
            $productByCode[$item['code']] = $record;
        }

        // 4b. Rubro secundario opcional (Polirrubro Mixto)
        if (! empty($secondaryTypes)) {
            $secType = $secondaryTypes[0];
            $secRubroName = SystemRubroSyncService::BUSINESS_TYPE_NAMES[$secType] ?? null;
            $secRubroId = $secRubroName ? Rubro::where('name', $secRubroName)->value('id') : null;
            if ($secRubroId) {
                $secCatId = DB::table('categories')->insertGetId([
                    'rubro_id' => $secRubroId,
                    'name' => 'Destacados '.($secRubroName ?? 'Anexo'),
                    'description' => 'Productos de rubro secundario en Polirrubro Mixto',
                    'created_at' => $nowStr,
                    'updated_at' => $nowStr,
                ]);
                $firstBrandId = reset($brandIds);
                foreach (DemoVerticalCatalogs::secondarySnippetFor($secType) as $sItem) {
                    $plu = str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
                    $seq++;
                    $isWeight = (bool) ($sItem['weight'] ?? false);
                    $secPayload = [
                        'name' => $sItem['name'],
                        'barcode' => $isWeight ? null : $barcodeService->generateInternalEan13($plu),
                        'internal_code' => $sItem['code'],
                        'cost_price' => round((float) $sItem['cost'], 2),
                        'selling_price' => round((float) $sItem['price'], 2),
                        'price_wholesale' => round((float) $sItem['price'] * 0.88, 2),
                        'price_card' => round((float) $sItem['price'] * 1.15, 2),
                        'iva_rate' => (float) ($sItem['iva'] ?? 21.00),
                        'stock' => (float) $sItem['stock'],
                        'min_stock' => (float) $sItem['min_stock'],
                        'active' => true,
                        'needs_catalog_review' => false,
                        'is_combo' => false,
                        'is_service' => false,
                        'is_sold_by_weight' => $isWeight,
                        'sales_count' => 0,
                        'unit_type' => (string) ($sItem['unit_type'] ?? 'un'),
                        'category_id' => $secCatId,
                        'brand_id' => $firstBrandId,
                        'supplier_id' => $supplierIds[1] ?? $supplierIds[0],
                        'created_at' => $nowStr,
                        'updated_at' => $nowStr,
                        'deleted_at' => null,
                    ];
                    if ($hasExtendedCols) {
                        $secPayload['supplier_sku'] = $sItem['code'];
                        $secPayload['storage_location'] = $sItem['loc'] ?? null;
                        $secPayload['technical_spec'] = $sItem['spec'] ?? null;
                    }
                    $pid = DB::table('products')->insertGetId($secPayload);
                    $record = array_merge($sItem, ['id' => $pid, 'track_batches' => false, 'is_service' => false]);
                    $productRecords[] = $record;
                    $productByCode[$sItem['code']] = $record;
                }
            }
        }

        // 5. Combo y Ofertas Activas
        $comboDef = $catalog['combo'];
        $comboPlu = str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
        $comboId = DB::table('products')->insertGetId([
            'name' => $comboDef['name'],
            'barcode' => $barcodeService->generateInternalEan13($comboPlu),
            'internal_code' => $comboDef['internal_code'],
            'cost_price' => 0,
            'selling_price' => $comboDef['selling_price'],
            'price_wholesale' => round($comboDef['selling_price'] * 0.90, 2),
            'price_card' => round($comboDef['selling_price'] * 1.15, 2),
            'iva_rate' => 21.00,
            'stock' => 10,
            'min_stock' => 2,
            'active' => true,
            'needs_catalog_review' => false,
            'is_combo' => true,
            'is_service' => false,
            'is_sold_by_weight' => false,
            'sales_count' => 0,
            'unit_type' => 'un',
            'category_id' => $categoryIds[$comboDef['category_key']] ?? reset($categoryIds),
            'brand_id' => $brandIds[$comboDef['brand']] ?? reset($brandIds),
            'supplier_id' => $supplierIds[0],
            'created_at' => $nowStr,
            'updated_at' => $nowStr,
            'deleted_at' => null,
        ]);
        foreach ($comboDef['items'] as $ci) {
            if (isset($productByCode[$ci['code']])) {
                DB::table('product_combos')->insert([
                    'parent_product_id' => $comboId,
                    'child_product_id' => $productByCode[$ci['code']]['id'],
                    'quantity' => (float) $ci['qty'],
                    'created_at' => $nowStr,
                    'updated_at' => $nowStr,
                ]);
            }
        }

        foreach ($catalog['offers'] as $off) {
            $offerId = DB::table('offers')->insertGetId([
                'name' => $off['name'],
                'description' => $off['description'],
                'type' => 'percentage',
                'discount_percentage' => $off['discount_percentage'],
                'discount_amount' => null,
                'period_type' => 'custom',
                'days_of_week' => null,
                'start_date' => $now->copy()->subDays(5)->startOfDay()->format('Y-m-d H:i:s'),
                'end_date' => $now->copy()->addDays(20)->endOfDay()->format('Y-m-d H:i:s'),
                'is_active' => true,
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ]);
            foreach ($off['product_codes'] as $pCode) {
                if (isset($productByCode[$pCode])) {
                    DB::table('offer_products')->insert([
                        'offer_id' => $offerId,
                        'product_id' => $productByCode[$pCode]['id'],
                        'special_price' => null,
                        'created_at' => $nowStr,
                        'updated_at' => $nowStr,
                    ]);
                }
            }
        }

        // 6. Facturas de Compra de Proveedores (para Libro IVA Compras y Posición IVA)
        $suppInvoiceIds = $this->seedSupplierInvoices($supplierIds, $productRecords, $actor->id, $now);

        // 7. Lotes FEFO y Merma (cuando aplique por vertical)
        $batchesByProductId = $this->seedBatchesAndWaste($productRecords, $supplierIds[0], $suppInvoiceIds[0] ?? null, $actor->id, $now);

        // 8. Clientes y saldos iniciales de Cuenta Corriente
        $customerIds = [];
        foreach ($catalog['customers'] as $cust) {
            $netBalance = round((float) $cust['seed_debt'] - (float) $cust['seed_payment'], 2);
            $custId = DB::table('customers')->insertGetId([
                'name' => $cust['name'],
                'phone' => $cust['phone'],
                'document_number' => $cust['document_number'],
                'document_type' => $cust['document_type'],
                'tax_condition' => $cust['tax_condition'],
                'delivery_address' => $cust['delivery_address'],
                'fiscal_address' => $cust['fiscal_address'],
                'credit_limit' => $cust['credit_limit'],
                'balance' => $netBalance,
                'is_active' => true,
                'default_price_tier' => $cust['default_price_tier'],
                'is_internal_account' => $cust['is_internal_account'],
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
                'deleted_at' => null,
            ]);
            $customerIds[] = $custId;

            if ((float) $cust['seed_debt'] > 0) {
                DB::table('customer_transactions')->insert([
                    'customer_id' => $custId,
                    'user_id' => $actor->id,
                    'cash_shift_id' => null,
                    'sale_id' => null,
                    'type' => 'charge',
                    'payment_method' => 'current_account',
                    'amount' => $cust['seed_debt'],
                    'balance_after' => $cust['seed_debt'],
                    'description' => 'Saldo inicial en cuenta corriente (Demo)',
                    'created_at' => $now->copy()->subDays(7)->format('Y-m-d H:i:s'),
                    'updated_at' => $now->copy()->subDays(7)->format('Y-m-d H:i:s'),
                ]);
                if ((float) $cust['seed_payment'] > 0) {
                    DB::table('customer_transactions')->insert([
                        'customer_id' => $custId,
                        'user_id' => $actor->id,
                        'cash_shift_id' => null,
                        'sale_id' => null,
                        'type' => 'payment',
                        'payment_method' => 'cash',
                        'amount' => $cust['seed_payment'],
                        'balance_after' => $netBalance,
                        'description' => 'Entrega a cuenta recibida (Recibo Demo)',
                        'created_at' => $now->copy()->subDays(2)->format('Y-m-d H:i:s'),
                        'updated_at' => $now->copy()->subDays(2)->format('Y-m-d H:i:s'),
                    ]);
                }
            }
        }

        // 9. Turnos Históricos Cerrados (1 hace 20 días p/ comparativa + 9 en últimos 10 días) + 1 Turno Abierto HOY
        $salesSummary = $this->seedShiftsAndSales(
            $actor->id,
            $productRecords,
            $customerIds,
            $batchesByProductId,
            $now
        );

        // 10. Presupuestos, Cheques de Terceros y Remitos de Logística
        $this->seedQuotes($catalog['quotes'], $productByCode, $actor->id, $now);
        $this->seedChecksAndDeliveryNotes(
            $catalog,
            $customerIds,
            $supplierIds,
            $salesSummary['recent_sale_ids'],
            $salesSummary['open_shift_id'],
            $productRecords,
            $now
        );

        // 11. Activar temporalmente listas de precios avanzadas en Ferretería/Bazar para lucirlas
        if (in_array($businessType, ['hardware_store', 'general'], true)) {
            $prevTierSetting = BusinessSetting::where('key', 'enable_advanced_price_tiers')->value('value');
            if ($prevTierSetting !== '1') {
                BusinessSetting::updateOrCreate(
                    ['key' => 'demo_prev_enable_advanced_price_tiers'],
                    ['value' => $prevTierSetting === null ? '__NULL__' : (string) $prevTierSetting]
                );
                BusinessSetting::updateOrCreate(
                    ['key' => 'enable_advanced_price_tiers'],
                    ['value' => '1']
                );
            }
        }

        $maxProductId = (int) DB::table('products')->max('id');
        BusinessSetting::updateOrCreate(['key' => 'demo_mode_active'], ['value' => '1']);
        BusinessSetting::updateOrCreate(['key' => 'demo_seeded_at'], ['value' => $now->toIso8601String()]);
        BusinessSetting::updateOrCreate(['key' => 'demo_onboarding_dismissed'], ['value' => '1']);
        BusinessSetting::updateOrCreate(['key' => 'demo_max_seeded_product_id'], ['value' => (string) $maxProductId]);

        return [
            'business_type' => $businessType,
            'products_count' => count($productRecords) + 1,
            'sales_count' => $salesSummary['total_sales'],
            'open_shift_id' => $salesSummary['open_shift_id'],
        ];
    }

    /**
     * @param  array<int, int>  $supplierIds
     * @param  array<int, array<string, mixed>>  $products
     * @return array<int, int>
     */
    private function seedSupplierInvoices(array $supplierIds, array $products, int $userId, Carbon $now): array
    {
        $invoiceIds = [];
        $samples = [
            ['sup_idx' => 0, 'days_ago' => 11, 'status' => 'paid', 'letter' => 'A', 'pos' => 1, 'num' => '00048211', 'items' => [0, 1, 2]],
            ['sup_idx' => 0, 'days_ago' => 5, 'status' => 'pending', 'letter' => 'A', 'pos' => 1, 'num' => '00049104', 'items' => [3, 4, 5]],
            ['sup_idx' => 1, 'days_ago' => 3, 'status' => 'partial', 'letter' => 'A', 'pos' => 2, 'num' => '00012890', 'items' => [6, 7, 8]],
        ];

        foreach ($samples as $sample) {
            $issueAt = $now->copy()->subDays($sample['days_ago'])->setTime(11, 15, 0);
            $issueStr = $issueAt->format('Y-m-d H:i:s');
            $net21 = 0.0;
            $itemRows = [];

            foreach ($sample['items'] as $pIdx) {
                if (! isset($products[$pIdx])) {
                    continue;
                }
                $prod = $products[$pIdx];
                if (! empty($prod['is_service'])) {
                    continue;
                }
                $qty = 12.0;
                $unitCost = max(100.0, (float) $prod['cost']);
                $sub = round($qty * $unitCost, 2);
                $net21 += $sub;
                $itemRows[] = [
                    'product_id' => $prod['id'],
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'subtotal' => $sub,
                    'created_at' => $issueStr,
                    'updated_at' => $issueStr,
                ];
            }

            if (empty($itemRows)) {
                continue;
            }

            $iva21 = round($net21 * 0.21, 2);
            $totalAmount = round($net21 + $iva21, 2);
            $supId = $supplierIds[$sample['sup_idx']] ?? $supplierIds[0];

            $invId = DB::table('supplier_invoices')->insertGetId([
                'supplier_id' => $supId,
                'type' => 'invoice',
                'voucher_letter' => $sample['letter'],
                'point_of_sale' => $sample['pos'],
                'voucher_number' => $sample['num'],
                'status' => $sample['status'],
                'amount' => $totalAmount,
                'tax_amount' => $iva21,
                'freight_amount' => 0,
                'discount_amount' => 0,
                'net_21' => round($net21, 2),
                'iva_21' => $iva21,
                'net_105' => 0,
                'iva_105' => 0,
                'net_27' => 0,
                'iva_27' => 0,
                'non_taxed_amount' => 0,
                'exempt_amount' => 0,
                'iva_perception' => 0,
                'iibb_perception' => 0,
                'internal_taxes' => 0,
                'affects_vat_book' => true,
                'invoice_number' => sprintf('%s %05d-%s', $sample['letter'], $sample['pos'], $sample['num']),
                'description' => 'Compra de reposición mercadería (Demo)',
                'issue_date' => $issueStr,
                'due_date' => $issueAt->copy()->addDays(30)->format('Y-m-d'),
                'user_id' => $userId,
                'created_at' => $issueStr,
                'updated_at' => $issueStr,
                'deleted_at' => null,
            ]);
            $invoiceIds[] = $invId;

            foreach ($itemRows as &$ir) {
                $ir['supplier_invoice_id'] = $invId;
            }
            unset($ir);
            DB::table('supplier_invoice_items')->insert($itemRows);
        }

        return $invoiceIds;
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @return array<int, array<int, array{id: int, unit_cost: float}>>
     */
    private function seedBatchesAndWaste(array $products, int $supplierId, ?int $supplierInvoiceId, int $userId, Carbon $now): array
    {
        if (! BatchStockService::hasLotTrackingSchema() || ! BusinessSetting::isBatchExpirationEnabledGlobally()) {
            return [];
        }

        $nowStr = $now->format('Y-m-d H:i:s');
        $batchesByProduct = [];
        $wasteLogged = false;

        foreach ($products as $prod) {
            if (empty($prod['track_batches'])) {
                continue;
            }

            $stock = (float) $prod['stock'];
            $mode = $prod['batch_mode'] ?? 'standard';
            $pid = (int) $prod['id'];
            $cost = (float) $prod['cost'];
            $batchesByProduct[$pid] = [];

            if ($mode === 'with_expired') {
                $expiredQty = round(min(2.0, $stock * 0.25), 3);
                $freshQty = round($stock - $expiredQty, 3);

                $expBatchId = DB::table('product_batches')->insertGetId([
                    'product_id' => $pid,
                    'batch_code' => 'LOT-VENC-'.$pid,
                    'source_type' => 'purchase',
                    'supplier_invoice_id' => $supplierInvoiceId,
                    'supplier_id' => $supplierId,
                    'manufactured_at' => $now->copy()->subDays(12)->format('Y-m-d'),
                    'expiration_date' => $now->copy()->subDays(2)->format('Y-m-d'),
                    'initial_quantity' => $expiredQty,
                    'remaining_quantity' => $expiredQty,
                    'reserved_quantity' => 0,
                    'unit_cost' => $cost,
                    'status' => 'active',
                    'notes' => 'Lote vencido de muestra para probar alertas/merma',
                    'created_by_user_id' => $userId,
                    'created_at' => $nowStr,
                    'updated_at' => $nowStr,
                    'deleted_at' => null,
                ]);
                $freshBatchId = DB::table('product_batches')->insertGetId([
                    'product_id' => $pid,
                    'batch_code' => 'LOT-VIG-'.$pid,
                    'source_type' => 'purchase',
                    'supplier_invoice_id' => $supplierInvoiceId,
                    'supplier_id' => $supplierId,
                    'manufactured_at' => $now->copy()->subDays(2)->format('Y-m-d'),
                    'expiration_date' => $now->copy()->addDays(14)->format('Y-m-d'),
                    'initial_quantity' => $freshQty,
                    'remaining_quantity' => $freshQty,
                    'reserved_quantity' => 0,
                    'unit_cost' => $cost,
                    'status' => 'active',
                    'notes' => 'Lote vigente en góndola/cámara',
                    'created_by_user_id' => $userId,
                    'created_at' => $nowStr,
                    'updated_at' => $nowStr,
                    'deleted_at' => null,
                ]);

                $batchesByProduct[$pid][] = ['id' => $freshBatchId, 'unit_cost' => $cost];

                DB::table('products')->where('id', $pid)->update([
                    'expiration_date' => $now->copy()->subDays(2)->format('Y-m-d'),
                ]);

                if (! $wasteLogged) {
                    $wasteLogged = true;
                    DB::table('stock_movements')->insert([
                        'product_id' => $pid,
                        'batch_id' => $expBatchId,
                        'user_id' => $userId,
                        'authorized_by_admin_id' => $userId,
                        'supplier_invoice_id' => null,
                        'cash_shift_id' => null,
                        'sale_id' => null,
                        'type' => 'waste',
                        'quantity' => -1.000,
                        'unit_cost' => $cost,
                        'notes' => 'Merma registrada por recorte/vencimiento (Demo)',
                        'created_at' => $now->copy()->subDays(1)->format('Y-m-d H:i:s'),
                        'updated_at' => $now->copy()->subDays(1)->format('Y-m-d H:i:s'),
                    ]);
                }
            } else {
                $expDays = max(2, (int) ($prod['exp_days'] ?? 15));
                $batchId = DB::table('product_batches')->insertGetId([
                    'product_id' => $pid,
                    'batch_code' => 'LOT-'.$now->format('ymd').'-'.$pid,
                    'source_type' => 'purchase',
                    'supplier_invoice_id' => $supplierInvoiceId,
                    'supplier_id' => $supplierId,
                    'manufactured_at' => $now->copy()->subDays(4)->format('Y-m-d'),
                    'expiration_date' => $now->copy()->addDays($expDays)->format('Y-m-d'),
                    'initial_quantity' => round($stock + 6, 3),
                    'remaining_quantity' => $stock,
                    'reserved_quantity' => 0,
                    'unit_cost' => $cost,
                    'status' => 'active',
                    'notes' => 'Lote activo FEFO (Demo)',
                    'created_by_user_id' => $userId,
                    'created_at' => $nowStr,
                    'updated_at' => $nowStr,
                    'deleted_at' => null,
                ]);
                $batchesByProduct[$pid][] = ['id' => $batchId, 'unit_cost' => $cost];
            }
        }

        return $batchesByProduct;
    }

    /**
     * Genera 10 turnos cerrados + 1 turno abierto hoy con fechas estrictamente <= now()->subMinutes(5)
     * para garantizar 0 colisiones con el control anti-rollback de reloj.
     *
     * @param  array<int, array<string, mixed>>  $products
     * @param  array<int, int>  $customerIds
     * @param  array<int, array<int, array{id: int, unit_cost: float}>>  $batchesByProduct
     * @return array{total_sales: int, open_shift_id: int, recent_sale_ids: array<int, int>}
     */
    private function seedShiftsAndSales(
        int $userId,
        array $products,
        array $customerIds,
        array $batchesByProduct,
        Carbon $now
    ): array {
        $pmIds = DB::table('payment_methods')->pluck('id', 'code')->toArray();
        $cashPmId = (int) ($pmIds['cash'] ?? 1);
        $debitPmId = (int) ($pmIds['card_debit'] ?? $cashPmId);
        $creditPmId = (int) ($pmIds['card_credit'] ?? $cashPmId);
        $transferPmId = (int) ($pmIds['transfer'] ?? $cashPmId);
        $mpPmId = (int) ($pmIds['mercadopago_qr'] ?? $cashPmId);
        $ccPmId = (int) ($pmIds['cuenta_corriente'] ?? $cashPmId);

        $expCatIds = DB::table('expense_categories')->pluck('id')->values()->all();
        $internalCustomerId = end($customerIds) ?: null;
        $normalCustomerIds = array_slice($customerIds, 0, max(1, count($customerIds) - 1));

        // 1 turno hace 20 días (para período comparativo positivo) + 9 turnos días -10..-1 + 1 turno abierto hoy
        $shiftPlans = [
            ['days_ago' => 20, 'sales_count' => 6, 'is_open' => false, 'opening' => 20000.00],
            ['days_ago' => 10, 'sales_count' => 7, 'is_open' => false, 'opening' => 25000.00],
            ['days_ago' => 9, 'sales_count' => 8, 'is_open' => false, 'opening' => 25000.00],
            ['days_ago' => 8, 'sales_count' => 7, 'is_open' => false, 'opening' => 25000.00],
            ['days_ago' => 7, 'sales_count' => 9, 'is_open' => false, 'opening' => 30000.00],
            ['days_ago' => 5, 'sales_count' => 8, 'is_open' => false, 'opening' => 30000.00],
            ['days_ago' => 4, 'sales_count' => 8, 'is_open' => false, 'opening' => 30000.00],
            ['days_ago' => 3, 'sales_count' => 9, 'is_open' => false, 'opening' => 30000.00],
            ['days_ago' => 2, 'sales_count' => 8, 'is_open' => false, 'opening' => 35000.00],
            ['days_ago' => 1, 'sales_count' => 9, 'is_open' => false, 'opening' => 35000.00],
            ['days_ago' => 0, 'sales_count' => 4, 'is_open' => true, 'opening' => 35000.00],
        ];

        $methodCycle = ['cash', 'cash', 'card_debit', 'transfer', 'card_credit', 'mercadopago_qr', 'cash', 'cuenta_corriente'];
        $totalProducts = count($products);
        $hasLotSchema = BatchStockService::hasLotTrackingSchema();

        $totalSalesCreated = 0;
        $openShiftId = 0;
        $recentSaleIds = [];
        $prodCursor = 0;

        foreach ($shiftPlans as $sIdx => $plan) {
            if ($plan['is_open']) {
                // Siempre estrictamente anterior a now() (incluso a las 00:05 de la madrugada)
                $openedAt = $now->copy()->subMinutes(95);
                $closedAt = null;
            } else {
                $dayBase = $now->copy()->subDays($plan['days_ago']);
                $openedAt = $dayBase->copy()->setTime(8, 30, 0);
                $closedAt = $dayBase->copy()->setTime(20, 45, 0);
                if ($closedAt->greaterThanOrEqualTo($now)) {
                    $closedAt = $now->copy()->subMinutes(15);
                }
                if ($openedAt->greaterThanOrEqualTo($closedAt)) {
                    $openedAt = $closedAt->copy()->subHours(8);
                }
            }

            $shiftId = DB::table('cash_shifts')->insertGetId([
                'cash_register_id' => 1,
                'user_id' => $userId,
                'opened_at' => $openedAt->format('Y-m-d H:i:s'),
                'closed_at' => $closedAt?->format('Y-m-d H:i:s'),
                'opening_balance' => $plan['opening'],
                'status' => $plan['is_open'] ? 'open' : 'closed',
                'created_at' => $openedAt->format('Y-m-d H:i:s'),
                'updated_at' => ($closedAt ?? $openedAt)->format('Y-m-d H:i:s'),
            ]);

            if ($plan['is_open']) {
                $openShiftId = $shiftId;
            }

            $cashSales = 0.0;
            $cardSales = 0.0;
            $transferSales = 0.0;
            $mpSales = 0.0;
            $mpCount = 0;
            $ccSales = 0.0;
            $ccCount = 0;
            $totalSurcharge = 0.0;
            $totalExpenses = 0.0;
            $totalDeposits = 0.0;

            $saleItemsBatch = [];
            $salePaymentsBatch = [];
            $stockMovementsBatch = [];
            $batchAllocationsBatch = [];

            for ($i = 0; $i < $plan['sales_count']; $i++) {
                if ($plan['is_open']) {
                    $saleAt = $now->copy()->subMinutes(max(12, 80 - ($i * 18)));
                } else {
                    $saleAt = $openedAt->copy()->addMinutes(45 + ($i * 65));
                    if ($closedAt && $saleAt->greaterThan($closedAt)) {
                        $saleAt = $closedAt->copy()->subMinutes(5);
                    }
                }
                $saleAtStr = $saleAt->format('Y-m-d H:i:s');

                $methodCode = $methodCycle[($sIdx + $i) % count($methodCycle)];
                $isInternalSale = ($sIdx === 8 && $i === 0 && $internalCustomerId !== null);
                $customerId = $isInternalSale
                    ? $internalCustomerId
                    : (($methodCode === 'cuenta_corriente' || $i % 3 === 0) ? $normalCustomerIds[$i % count($normalCustomerIds)] : null);

                // Construir entre 1 y 3 ítems por ticket
                $itemsInTicket = ($i % 3) + 1;
                $baseSubtotal = 0.0;
                $pendingItems = [];

                for ($k = 0; $k < $itemsInTicket; $k++) {
                    $prod = $products[$prodCursor % $totalProducts];
                    $prodCursor += 3;
                    $qty = (! empty($prod['weight'])) ? 1.250 : (($k === 0) ? 2.000 : 1.000);
                    $unitPrice = (float) $prod['price'];
                    $unitCost = (float) $prod['cost'];
                    $ivaRate = (float) ($prod['iva'] ?? 21.00);
                    $lineSub = round($qty * $unitPrice, 2);
                    $netAmount = round($lineSub / (1 + ($ivaRate / 100)), 2);
                    $ivaAmount = round($lineSub - $netAmount, 2);
                    $baseSubtotal += $lineSub;

                    $pendingItems[] = [
                        'prod' => $prod,
                        'qty' => $qty,
                        'unit_price' => $unitPrice,
                        'unit_cost' => $unitCost,
                        'iva_rate' => $ivaRate,
                        'iva_amount' => $ivaAmount,
                        'net_amount' => $netAmount,
                        'subtotal' => $lineSub,
                    ];
                }

                $surcharge = ($methodCode === 'card_credit') ? round($baseSubtotal * 0.15, 2) : 0.0;
                $grandTotal = round($baseSubtotal + $surcharge, 2);
                $isCc = $methodCode === 'cuenta_corriente';

                $saleId = DB::table('sales')->insertGetId([
                    'client_transaction_id' => (string) Str::uuid(),
                    'total' => $grandTotal,
                    'total_surcharge' => $surcharge,
                    'tendered_amount' => $methodCode === 'cash' ? $grandTotal : null,
                    'change_amount' => $methodCode === 'cash' ? 0.00 : null,
                    'payment_status' => $isCc ? 'pending' : 'paid',
                    'amount_due' => $isCc ? $grandTotal : 0.00,
                    'status' => 'completed',
                    'invoice_status' => 'none',
                    'cash_shift_id' => $shiftId,
                    'user_id' => $userId,
                    'cashier_id' => $userId,
                    'customer_id' => $customerId,
                    'shipping_cost' => 0.00,
                    'price_list' => 'base',
                    'iibb_perception_amount' => 0.00,
                    'iibb_perception_rate' => 0.00,
                    'created_at' => $saleAtStr,
                    'updated_at' => $saleAtStr,
                ]);
                $totalSalesCreated++;

                if ($sIdx >= 8) {
                    $recentSaleIds[] = $saleId;
                }

                foreach ($pendingItems as $pi) {
                    $prod = $pi['prod'];
                    $saleItemId = DB::table('sale_items')->insertGetId([
                        'sale_id' => $saleId,
                        'product_id' => $prod['id'],
                        'is_temporary' => false,
                        'product_name' => $prod['name'],
                        'custom_description' => null,
                        'custom_details' => null,
                        'quantity' => $pi['qty'],
                        'unit_cost_price' => $pi['unit_cost'],
                        'unit_price' => $pi['unit_price'],
                        'iva_rate' => $pi['iva_rate'],
                        'iva_amount' => $pi['iva_amount'],
                        'net_amount' => $pi['net_amount'],
                        'subtotal' => $pi['subtotal'],
                        'created_at' => $saleAtStr,
                        'updated_at' => $saleAtStr,
                    ]);

                    if (empty($prod['is_service'])) {
                        $stockMovementsBatch[] = [
                            'product_id' => $prod['id'],
                            'batch_id' => $batchesByProduct[$prod['id']][0]['id'] ?? null,
                            'user_id' => $userId,
                            'cash_shift_id' => $shiftId,
                            'sale_id' => $saleId,
                            'type' => 'sale',
                            'quantity' => -round($pi['qty'], 3),
                            'unit_cost' => $pi['unit_cost'],
                            'notes' => 'Venta Ticket #'.$saleId,
                            'created_at' => $saleAtStr,
                            'updated_at' => $saleAtStr,
                        ];

                        if ($hasLotSchema && ! empty($batchesByProduct[$prod['id']][0]['id'])) {
                            $batchAllocationsBatch[] = [
                                'sale_id' => $saleId,
                                'sale_item_id' => $saleItemId,
                                'product_id' => $prod['id'],
                                'batch_id' => $batchesByProduct[$prod['id']][0]['id'],
                                'quantity' => round($pi['qty'], 3),
                                'restored_quantity' => 0,
                                'unit_cost' => $pi['unit_cost'],
                                'created_at' => $saleAtStr,
                                'updated_at' => $saleAtStr,
                            ];
                        }
                    }
                }
                unset($saleItemsBatch);

                $pmId = match ($methodCode) {
                    'card_debit' => $debitPmId,
                    'card_credit' => $creditPmId,
                    'transfer' => $transferPmId,
                    'mercadopago_qr' => $mpPmId,
                    'cuenta_corriente' => $ccPmId,
                    default => $cashPmId,
                };

                $salePaymentsBatch[] = [
                    'sale_id' => $saleId,
                    'payment_method_id' => $pmId,
                    'base_amount' => $baseSubtotal,
                    'surcharge_amount' => $surcharge,
                    'total_amount' => $grandTotal,
                    'reference_id' => $methodCode === 'transfer' ? 'TRF-'.(1000 + $saleId) : null,
                    'transfer_account_alias' => $methodCode === 'transfer' ? 'caja4u.demo.mp' : null,
                    'transfer_sender_name' => $methodCode === 'transfer' ? 'Cliente Demo Transferencia' : null,
                    'created_at' => $saleAtStr,
                ];

                if ($methodCode === 'cash') {
                    $cashSales += $grandTotal;
                } elseif (str_starts_with($methodCode, 'card_')) {
                    $cardSales += $grandTotal;
                } elseif ($methodCode === 'transfer') {
                    $transferSales += $grandTotal;
                } elseif ($methodCode === 'mercadopago_qr') {
                    $mpSales += $grandTotal;
                    $mpCount++;
                } elseif ($methodCode === 'cuenta_corriente') {
                    $ccSales += $grandTotal;
                    $ccCount++;
                }
                $totalSurcharge += $surcharge;
            }

            if (! empty($salePaymentsBatch)) {
                DB::table('sale_payments')->insert($salePaymentsBatch);
            }
            if (! empty($stockMovementsBatch)) {
                DB::table('stock_movements')->insert($stockMovementsBatch);
            }
            if (! empty($batchAllocationsBatch)) {
                DB::table('sale_item_batch_allocations')->insert($batchAllocationsBatch);
            }

            // Movimientos de caja (gastos e ingresos de cambio)
            if ($plan['is_open'] || $sIdx % 2 === 1) {
                $movAt = $plan['is_open'] ? $now->copy()->subMinutes(50)->format('Y-m-d H:i:s') : $openedAt->copy()->addHours(2)->format('Y-m-d H:i:s');
                $expCatId = ! empty($expCatIds) ? $expCatIds[$sIdx % count($expCatIds)] : null;
                $expenseAmt = $plan['is_open'] ? 4800.00 : 6500.00;
                $totalExpenses += $expenseAmt;

                DB::table('cash_movements')->insert([
                    'cash_shift_id' => $shiftId,
                    'user_id' => $userId,
                    'authorized_by' => $userId,
                    'amount' => $expenseAmt,
                    'payment_method' => 'cash',
                    'funding_source' => 'cash_register',
                    'type' => 'expense',
                    'category' => 'Gastos Operativos',
                    'expense_category_id' => $expCatId,
                    'description' => $plan['is_open'] ? 'Rollos papel térmico 80mm e insumos mostrador' : 'Compra insumos limpieza y bolsas',
                    'receipt_number' => 'REC-'.(400 + $shiftId),
                    'created_at' => $movAt,
                    'updated_at' => $movAt,
                    'deleted_at' => null,
                ]);

                if ($plan['is_open']) {
                    $depositAmt = 15000.00;
                    $totalDeposits += $depositAmt;
                    DB::table('cash_movements')->insert([
                        'cash_shift_id' => $shiftId,
                        'user_id' => $userId,
                        'authorized_by' => $userId,
                        'amount' => $depositAmt,
                        'payment_method' => 'cash',
                        'funding_source' => 'cash_register',
                        'type' => 'deposit',
                        'category' => 'Cambio',
                        'expense_category_id' => null,
                        'description' => 'Refuerzo de cambio en billetes chicos',
                        'receipt_number' => null,
                        'created_at' => $movAt,
                        'updated_at' => $movAt,
                        'deleted_at' => null,
                    ]);
                }
            }

            if (! $plan['is_open']) {
                $expectedBalance = round($plan['opening'] + $cashSales + $totalDeposits - $totalExpenses, 2);
                DB::table('cash_shifts')->where('id', $shiftId)->update([
                    'expected_balance' => $expectedBalance,
                    'actual_balance' => $expectedBalance,
                    'difference' => 0.00,
                    'cash_sales' => round($cashSales, 2),
                    'card_sales' => round($cardSales, 2),
                    'transfer_sales' => round($transferSales, 2),
                    'mp_sales' => round($mpSales, 2),
                    'mp_sales_count' => $mpCount,
                    'total_surcharge' => round($totalSurcharge, 2),
                    'check_sales' => 0.00,
                    'check_count' => 0,
                    'cc_sales' => round($ccSales, 2),
                    'cc_sales_count' => $ccCount,
                    'total_expenses' => round($totalExpenses, 2),
                    'total_withdrawals' => 0.00,
                    'total_deposits' => round($totalDeposits, 2),
                    'total_supplier_payments' => 0.00,
                    'total_refunds' => 0.00,
                ]);
            }
        }

        return [
            'total_sales' => $totalSalesCreated,
            'open_shift_id' => $openShiftId,
            'recent_sale_ids' => $recentSaleIds,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $quotes
     * @param  array<string, array<string, mixed>>  $productByCode
     */
    private function seedQuotes(array $quotes, array $productByCode, int $userId, Carbon $now): void
    {
        if (! Schema::hasTable('quotes') || ! Schema::hasTable('quote_items')) {
            return;
        }

        $nowStr = $now->format('Y-m-d H:i:s');
        foreach ($quotes as $idx => $q) {
            $subtotal = 0.0;
            $items = [];
            foreach ($q['items'] as $it) {
                $prod = $productByCode[$it['code']] ?? null;
                if (! $prod) {
                    continue;
                }
                $qty = (float) $it['qty'];
                $price = (float) $prod['price'];
                $sub = round($qty * $price, 2);
                $subtotal += $sub;
                $items[] = [
                    'product_id' => $prod['id'],
                    'product_name' => $prod['name'],
                    'unit_price' => $price,
                    'quantity' => $qty,
                    'subtotal' => $sub,
                    'created_at' => $nowStr,
                    'updated_at' => $nowStr,
                ];
            }
            if (empty($items)) {
                continue;
            }

            $quoteId = DB::table('quotes')->insertGetId([
                'quote_number' => sprintf('PRES-%04d', $idx + 1),
                'status' => $q['status'],
                'subtotal' => round($subtotal, 2),
                'total' => round($subtotal, 2),
                'notes' => $q['notes'],
                'customer_name' => $q['customer_name'],
                'customer_phone' => $q['customer_phone'],
                'valid_until' => $now->copy()->addDays((int) $q['days_offset'])->format('Y-m-d'),
                'price_list' => $q['price_list'] ?? 'base',
                'user_id' => $userId,
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ]);

            foreach ($items as &$itemRow) {
                $itemRow['quote_id'] = $quoteId;
            }
            unset($itemRow);
            DB::table('quote_items')->insert($items);
        }
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @param  array<int, int>  $customerIds
     * @param  array<int, int>  $supplierIds
     * @param  array<int, int>  $recentSaleIds
     * @param  array<int, array<string, mixed>>  $products
     */
    private function seedChecksAndDeliveryNotes(
        array $catalog,
        array $customerIds,
        array $supplierIds,
        array $recentSaleIds,
        int $openShiftId,
        array $products,
        Carbon $now
    ): void {
        $nowStr = $now->format('Y-m-d H:i:s');

        if (Schema::hasTable('third_party_checks')) {
            foreach ($catalog['checks'] as $idx => $chk) {
                DB::table('third_party_checks')->insert([
                    'bank_name' => $chk['bank'],
                    'check_number' => $chk['number'],
                    'amount' => $chk['amount'],
                    'issue_date' => $now->copy()->subDays(10)->format('Y-m-d'),
                    'payment_date' => $now->copy()->addDays((int) $chk['days_due'])->format('Y-m-d'),
                    'issuer_name' => $chk['issuer'],
                    'issuer_cuit' => $chk['cuit'],
                    'customer_id' => $customerIds[$idx % count($customerIds)] ?? null,
                    'sale_id' => $recentSaleIds[$idx % max(1, count($recentSaleIds))] ?? null,
                    'supplier_id' => $chk['status'] === 'endorsed' ? ($supplierIds[0] ?? null) : null,
                    'cash_shift_id' => $openShiftId > 0 ? $openShiftId : null,
                    'status' => $chk['status'],
                    'endorsement_note' => $chk['status'] === 'endorsed' ? 'Endosado a proveedor principal (Demo)' : null,
                    'created_at' => $nowStr,
                    'updated_at' => $nowStr,
                ]);
            }
        }

        if (! empty($catalog['delivery_notes']) && Schema::hasTable('delivery_notes') && count($recentSaleIds) >= 2) {
            $statuses = ['pending', 'partial'];
            foreach ($statuses as $idx => $dnStatus) {
                $saleId = $recentSaleIds[$idx];
                $saleItems = DB::table('sale_items')->where('sale_id', $saleId)->get();
                if ($saleItems->isEmpty()) {
                    continue;
                }
                $noteId = DB::table('delivery_notes')->insertGetId([
                    'sale_id' => $saleId,
                    'status' => $dnStatus,
                    'notes' => $dnStatus === 'pending'
                        ? 'Acopio en corralón pendiente de envío en flete (Demo)'
                        : 'Entrega parcial en obra — resta segundo viaje (Demo)',
                    'created_at' => $nowStr,
                    'updated_at' => $nowStr,
                ]);

                foreach ($saleItems as $sIdx => $si) {
                    if (empty($si->product_id)) {
                        continue;
                    }
                    $qtyPurchased = (float) $si->quantity;
                    $qtyDelivered = ($dnStatus === 'partial' && $sIdx === 0) ? round($qtyPurchased * 0.5, 3) : 0.0;
                    DB::table('delivery_note_items')->insert([
                        'delivery_note_id' => $noteId,
                        'product_id' => $si->product_id,
                        'quantity_purchased' => $qtyPurchased,
                        'quantity_delivered' => $qtyDelivered,
                        'created_at' => $nowStr,
                        'updated_at' => $nowStr,
                    ]);
                }
            }
        }
    }
}
