<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Product;
use App\Models\StockMovement;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAsAdmin($this->admin);
    }

    public function test_r01_stock_kardex_report()
    {
        $product = Product::create(['name' => 'P1', 'internal_code' => 'R01', 'cost_price' => 10, 'selling_price' => 20]);
        StockMovement::create([
            'product_id' => $product->id,
            'type' => 'in',
            'quantity' => 10,
            'user_id' => $this->admin->id
        ]);

        $response = $this->getJson("/api/audit/stock?product_id={$product->id}");
        $response->assertStatus(200)
                 ->assertJsonCount(1, 'data');
    }

    public function test_r02_profit_by_category()
    {
        $response = $this->getJson("/api/reports/sales-by-category?start_date=2024-01-01&end_date=2025-01-01");
        $response->assertStatus(200);
    }

    public function test_r03_profit_by_brand()
    {
        $response = $this->getJson("/api/reports/sales-by-brand?start_date=2024-01-01&end_date=2025-01-01");
        $response->assertStatus(200);
    }

    public function test_r04_internal_consumption()
    {
        $response = $this->getJson("/api/reports/internal-consumption?start_date=2024-01-01&end_date=2025-01-01");
        $response->assertStatus(200);
    }

    public function test_r05_monthly_balance()
    {
        $response = $this->getJson("/api/reports/monthly-balance?start_month=2024-01&end_month=2025-01");
        $response->assertStatus(200);
    }
}
