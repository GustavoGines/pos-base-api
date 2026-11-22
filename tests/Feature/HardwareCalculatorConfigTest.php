<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardwareCalculatorConfigTest extends TestCase
{
    use RefreshDatabase;

    private function enableHardwareFeatures(): void
    {
        BusinessSetting::updateOrCreate(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['quotes' => true, 'multiple_prices' => true])]
        );
        BusinessSetting::updateOrCreate(
            ['key' => 'license_business_type'],
            ['value' => 'hardware_store']
        );
    }

    public function test_cashier_can_patch_product_calc_config_without_admin_pin_and_cannot_tamper_price_or_stock(): void
    {
        $this->enableHardwareFeatures();
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'permissions' => [],
        ]);

        $product = Product::create([
            'name' => 'Machimbre Pino 1/2 x 4 x 3.00m',
            'internal_code' => '00101',
            'cost_price' => 1500.00,
            'selling_price' => 2500.00,
            'stock' => 120.000,
            'active' => true,
        ]);

        $this->actingAsAdmin($cashier);

        $response = $this->patchJson("/api/catalog/products/{$product->id}/calc-config", [
            'calc_config' => [
                'type' => 'wood',
                'inch_preset' => '4',
                'nominal_width_m' => 0.1016,
                'useful_width_m' => 0.088,
                'length_m' => 3.00,
                'pricing_mode' => 'per_m2',
                'm2_billing_basis' => 'nominal',
                'boards_per_pack' => 10,
                'round_to_full_pack' => false,
                'default_waste_pct' => 10,
                'unallowed_key' => 'should_be_stripped',
            ],
            // Intentos de inyección sobre campos críticos: deben ser ignorados por completo
            'selling_price' => 10.00,
            'cost_price' => 1.00,
            'stock' => 9999,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('calc_config.type', 'wood')
            ->assertJsonPath('calc_config.useful_width_m', 0.088)
            ->assertJsonPath('calc_config.pricing_mode', 'per_m2')
            ->assertJsonPath('calc_config.m2_billing_basis', 'nominal');

        $product->refresh();
        $this->assertEquals(2500.00, (float) $product->selling_price);
        $this->assertEquals(1500.00, (float) $product->cost_price);
        $this->assertEquals(120.000, (float) $product->stock);
        $this->assertIsArray($product->calc_config);
        $this->assertArrayNotHasKey('unallowed_key', $product->calc_config);
        $this->assertEquals(0.088, $product->calc_config['useful_width_m']);
    }

    public function test_quote_and_sale_support_decimal_m2_quantities_and_preserve_custom_details(): void
    {
        $this->enableHardwareFeatures();
        $admin = User::factory()->create(['role' => 'admin']);

        $product = Product::create([
            'name' => 'Machimbre 1/2 x 4 (Venta por m2)',
            'internal_code' => '00102',
            'cost_price' => 4500.00,
            'selling_price' => 8000.00,
            'stock' => 100.000,
            'is_sold_by_weight' => false,
            'active' => true,
        ]);

        $this->actingAsAdmin($admin);

        $calcDetail = 'Cálculo: 45 tablas de 3.00m (cubre 12.15 m² útiles · facturado 13.72 m² nom. · +10% desp.)';

        // 1. Crear presupuesto con cantidad decimal (m2) y custom_details
        $quoteRes = $this->postJson('/api/quotes', [
            'customer_name' => 'Juan Constructor',
            'price_list' => 'base',
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'custom_details' => $calcDetail,
                    'unit_price' => 8000.00,
                    'quantity' => 13.716,
                ],
            ],
        ]);

        $quoteRes->assertStatus(201)
            ->assertJsonPath('items.0.custom_details', $calcDetail);

        $quoteId = $quoteRes->json('id');

        // 2. Procesar venta recuperando ese presupuesto con cantidad decimal
        $register = CashRegister::create(['name' => 'Caja Principal', 'is_active' => true]);
        $shift = CashShift::create([
            'cash_register_id' => $register->id,
            'user_id' => $admin->id,
            'opening_balance' => 10000,
            'status' => 'open',
            'opened_at' => now(),
        ]);
        $cashMethod = PaymentMethod::create([
            'name' => 'Efectivo',
            'code' => 'efectivo',
            'is_cash' => true,
            'is_active' => true,
        ]);

        $subtotal = round(8000.00 * 13.716, 2); // 109728.00

        $saleRes = $this->postJson('/api/pos/sales', [
            'cash_shift_id' => $shift->id,
            'quote_id' => $quoteId,
            'total' => $subtotal,
            'total_surcharge' => 0,
            'status' => 'completed',
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'custom_details' => $calcDetail,
                    'quantity' => 13.716,
                    'unit_price' => 8000.00,
                    'subtotal' => $subtotal,
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $cashMethod->id,
                    'base_amount' => $subtotal,
                    'surcharge_amount' => 0,
                    'total_amount' => $subtotal,
                ],
            ],
        ]);

        $saleRes->assertStatus(201)
            ->assertJsonPath('sale.items.0.custom_details', $calcDetail);

        $product->refresh();
        $this->assertEqualsWithDelta(100.000 - 13.716, (float) $product->stock, 0.002);
    }

    public function test_patch_calc_config_rejects_empty_body_without_wiping_existing_config(): void
    {
        $this->enableHardwareFeatures();
        $cashier = User::factory()->create(['role' => 'cashier']);
        $initialConfig = [
            'type' => 'wood',
            'nominal_width_m' => 0.1016,
            'useful_width_m' => 0.088,
            'length_m' => 3.00,
        ];
        $product = Product::create([
            'name' => 'Machimbre Pino 4"',
            'internal_code' => '00103',
            'cost_price' => 1500.00,
            'selling_price' => 2500.00,
            'stock' => 50.000,
            'active' => true,
            'calc_config' => $initialConfig,
        ]);

        $this->actingAsAdmin($cashier);

        $response = $this->patchJson("/api/catalog/products/{$product->id}/calc-config", []);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['calc_config']);

        $product->refresh();
        $this->assertEquals($initialConfig, $product->calc_config);
    }

    public function test_patch_calc_config_rejects_useful_width_greater_than_nominal_width(): void
    {
        $this->enableHardwareFeatures();
        $cashier = User::factory()->create(['role' => 'cashier']);
        $product = Product::create([
            'name' => 'Chapa Sinusoidal',
            'internal_code' => '00104',
            'cost_price' => 15000.00,
            'selling_price' => 25000.00,
            'stock' => 20.000,
            'active' => true,
        ]);

        $this->actingAsAdmin($cashier);

        $response = $this->patchJson("/api/catalog/products/{$product->id}/calc-config", [
            'calc_config' => [
                'type' => 'roof',
                'nominal_width_m' => 0.10,
                'useful_width_m' => 0.12,
                'length_m' => 3.50,
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['calc_config.useful_width_m']);
    }

    public function test_patch_calc_config_blocks_retail_business_type(): void
    {
        BusinessSetting::updateOrCreate(
            ['key' => 'license_business_type'],
            ['value' => 'retail']
        );
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'name' => 'Galletitas Dulces',
            'internal_code' => '00105',
            'cost_price' => 500.00,
            'selling_price' => 900.00,
            'stock' => 30.000,
            'active' => true,
        ]);

        $this->actingAsAdmin($admin);

        $response = $this->patchJson("/api/catalog/products/{$product->id}/calc-config", [
            'calc_config' => [
                'type' => 'wood',
                'nominal_width_m' => 0.1016,
                'useful_width_m' => 0.088,
            ],
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('error_code', 'HARDWARE_STORE_ONLY');
    }

    public function test_patch_calc_config_allows_explicit_null_to_clear(): void
    {
        $this->enableHardwareFeatures();
        $cashier = User::factory()->create(['role' => 'cashier']);
        $product = Product::create([
            'name' => 'Machimbre a limpiar',
            'internal_code' => '00106',
            'cost_price' => 1500.00,
            'selling_price' => 2500.00,
            'stock' => 20.000,
            'active' => true,
            'calc_config' => ['type' => 'wood', 'length_m' => 3.0],
        ]);

        $this->actingAsAdmin($cashier);

        $response = $this->patchJson("/api/catalog/products/{$product->id}/calc-config", [
            'calc_config' => null,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('calc_config', null);

        $product->refresh();
        $this->assertNull($product->calc_config);
    }
}
