<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowcaseFeatureGatingReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAsAdmin($this->admin);
    }

    private function configureBasicLicense(): void
    {
        BusinessSetting::updateOrCreate(['key' => 'app_plan'], ['value' => 'basic']);
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode([
                'fast_pos' => true,
                'z_reports' => true,
                'mercadopago_qr' => true,
                'arca_afip' => true,
                'mobile_app' => true,
                'advanced_reports' => false,
                'suppliers' => false,
                'expenses' => false,
                'current_accounts' => false,
                'multi_rubro' => false,
            ])]
        );
    }

    private function configurePremiumLicense(): void
    {
        BusinessSetting::updateOrCreate(['key' => 'app_plan'], ['value' => 'premium']);
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode([
                'fast_pos' => true,
                'z_reports' => true,
                'mercadopago_qr' => true,
                'arca_afip' => true,
                'mobile_app' => true,
                'advanced_reports' => true,
                'suppliers' => true,
                'expenses' => true,
                'current_accounts' => true,
                'multi_rubro' => true,
            ])]
        );
    }

    public function test_basic_plan_allows_general_sales_and_vat_sales_screen_consultation_only(): void
    {
        $this->configureBasicLicense();

        $this->getJson('/api/reports/sales-by-category?start_date=2026-11-01&end_date=2026-11-30')
            ->assertStatus(200);
        $this->getJson('/api/reports/sales-by-brand?start_date=2026-11-01&end_date=2026-11-30')
            ->assertStatus(200);
        $this->getJson('/api/reports/vat-sales?start_date=2026-11-01&end_date=2026-11-30')
            ->assertStatus(200);
    }

    public function test_basic_plan_blocks_advanced_reports_exports_and_vat_purchases_position(): void
    {
        $this->configureBasicLicense();

        $advancedReportsRoutes = [
            '/api/reports/sales-by-category/export?start_date=2026-11-01&end_date=2026-11-30',
            '/api/reports/sales-by-category/pdf?start_date=2026-11-01&end_date=2026-11-30',
            '/api/reports/monthly-balance?start_month=2026-11&end_month=2026-11',
            '/api/reports/monthly-balance/export?start_month=2026-11&end_month=2026-11',
            '/api/reports/monthly-balance/pdf?start_month=2026-11&end_month=2026-11',
            '/api/reports/vat-sales/export?start_date=2026-11-01&end_date=2026-11-30',
            '/api/reports/vat-sales/pdf?start_date=2026-11-01&end_date=2026-11-30',
        ];

        foreach ($advancedReportsRoutes as $uri) {
            $this->getJson($uri)
                ->assertStatus(403)
                ->assertJsonPath('error_code', 'FEATURE_NOT_LICENSED')
                ->assertJsonPath('required', 'advanced_reports');
        }

        $supplierGatedRoutes = [
            '/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30',
            '/api/reports/vat-purchases/export?start_date=2026-11-01&end_date=2026-11-30',
            '/api/reports/vat-purchases/pdf?start_date=2026-11-01&end_date=2026-11-30',
            '/api/reports/vat-position?month=11&year=2026',
        ];

        foreach ($supplierGatedRoutes as $uri) {
            $this->getJson($uri)
                ->assertStatus(403)
                ->assertJsonPath('error_code', 'FEATURE_NOT_LICENSED')
                ->assertJsonPath('required', 'suppliers');
        }

        $this->getJson('/api/reports/expenses-analysis?start_date=2026-11-01&end_date=2026-11-30')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FEATURE_NOT_LICENSED')
            ->assertJsonPath('required', 'expenses');

        $this->getJson('/api/reports/internal-consumption?start_date=2026-11-01&end_date=2026-11-30')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FEATURE_NOT_LICENSED')
            ->assertJsonPath('required', 'current_accounts');
    }

    public function test_premium_plan_allows_all_reports_exports_and_vat_endpoints(): void
    {
        $this->configurePremiumLicense();

        $endpoints = [
            '/api/reports/sales-by-category/export?start_date=2026-11-01&end_date=2026-11-30',
            '/api/reports/sales-by-category/pdf?start_date=2026-11-01&end_date=2026-11-30',
            '/api/reports/monthly-balance?start_month=2026-11&end_month=2026-11',
            '/api/reports/monthly-balance/export?start_month=2026-11&end_month=2026-11',
            '/api/reports/monthly-balance/pdf?start_month=2026-11&end_month=2026-11',
            '/api/reports/vat-sales/export?start_date=2026-11-01&end_date=2026-11-30',
            '/api/reports/vat-sales/pdf?start_date=2026-11-01&end_date=2026-11-30',
            '/api/reports/vat-purchases?start_date=2026-11-01&end_date=2026-11-30',
            '/api/reports/vat-purchases/export?start_date=2026-11-01&end_date=2026-11-30',
            '/api/reports/vat-purchases/pdf?start_date=2026-11-01&end_date=2026-11-30',
            '/api/reports/vat-position?month=11&year=2026',
            '/api/reports/expenses-analysis?start_date=2026-11-01&end_date=2026-11-30',
            '/api/reports/internal-consumption?start_date=2026-11-01&end_date=2026-11-30',
        ];

        foreach ($endpoints as $uri) {
            $this->get($uri)->assertStatus(200);
        }
    }

    public function test_basic_plan_blocks_cash_movements_and_delivery_notes_while_allowing_close_shift_and_sale_void(): void
    {
        $this->configureBasicLicense();
        $this->admin->update(['pin' => \Illuminate\Support\Facades\Hash::make('1234')]);

        $shift = $this->crearTurnoAbierto(1000.00, $this->admin);
        $cashMethod = $this->crearMetodoEfectivo();
        $product = \App\Models\Product::create([
            'name' => 'Producto Básico Test',
            'internal_code' => 'BAS-001',
            'cost_price' => 50,
            'selling_price' => 100,
            'stock' => 20,
        ]);

        // 1. Billetera / Movimientos de Caja bloqueados con 403 en Básico
        $this->getJson('/api/cash-movements')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FEATURE_NOT_LICENSED')
            ->assertJsonPath('required', 'expenses');

        $this->postJson('/api/cash-movements', [
            'type' => 'expense',
            'description' => 'Compra de artículos de limpieza',
            'payments' => [
                ['amount' => 100, 'payment_method' => 'cash'],
            ],
        ])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FEATURE_NOT_LICENSED')
            ->assertJsonPath('required', 'expenses');

        // 2. Remitos / Logística bloqueados con 403 en Básico Retail
        $this->getJson('/api/delivery-notes')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FEATURE_NOT_LICENSED')
            ->assertJsonPath('required', 'logistics');

        // 3. Procesar venta, anular venta y cerrar turno (Cierre Z) siguen operando en Básico sin 403
        $saleResp = $this->postJson('/api/pos/sales', [
            'cash_shift_id' => $shift->id,
            'total' => 200,
            'total_surcharge' => 0,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => 100,
                    'subtotal' => 200,
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $cashMethod->id,
                    'base_amount' => 200,
                    'surcharge_amount' => 0,
                    'total_amount' => 200,
                ],
            ],
        ])->assertCreated();

        $saleId = $saleResp->json('sale.id');

        $this->postJson("/api/sales/{$saleId}/void", [
            'reason' => 'Anulación de prueba Plan Básico',
        ])->assertStatus(200);

        $this->postJson("/api/shifts/{$shift->id}/close", [
            'actual_balance' => 1000.00,
            'pin' => '1234',
        ])->assertStatus(200);
    }

    public function test_basic_hardware_store_allows_delivery_notes_while_blocking_cash_movements(): void
    {
        BusinessSetting::updateOrCreate(['key' => 'app_plan'], ['value' => 'basic']);
        BusinessSetting::updateOrCreate(['key' => 'license_business_type'], ['value' => 'hardware_store']);
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode([
                'fast_pos' => true,
                'z_reports' => true,
                'quotes' => true,
                'logistics' => true,
                'multiple_prices' => true,
                'expenses' => false,
                'suppliers' => false,
                'current_accounts' => false,
                'advanced_reports' => false,
            ])]
        );

        $this->getJson('/api/delivery-notes')->assertStatus(200);
        $this->getJson('/api/cash-movements')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FEATURE_NOT_LICENSED')
            ->assertJsonPath('required', 'expenses');
    }
}
