<?php

namespace Tests\Feature;

use App\Constants\Permissions;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * CatalogBulkChallengerTest
 *
 * Empirical stress harness and adversarial challenge suite
 * for POST /api/catalog/bulk-update.
 */
class CatalogBulkChallengerTest extends TestCase
{
    use RefreshDatabase;

    protected $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('1234'),
        ]);
    }

    protected function actingAsCashierWithUser(?User $user = null, array $permissions = []): User
    {
        $token = 'cashier-token-' . uniqid();
        if ($user === null) {
            $user = User::factory()->create([
                'role' => 'cashier',
                'pin' => Hash::make('5678'),
                'permissions' => $permissions,
            ]);
        }
        DB::table('users')->where('id', $user->id)->update(['session_token' => $token]);
        $this->withHeader('X-Session-Token', $token);

        return $user;
    }

    /*
     |--------------------------------------------------------------------------
     | 1. AUTHENTICATION & PIN SECURITY MATRICES
     |--------------------------------------------------------------------------
     */

    public function test_unauthenticated_request_rejected_with_401_session_missing(): void
    {
        // No session token, no auth headers
        $response = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => [1],
            'active' => false,
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error_code', 'SESSION_MISSING');
    }

    public function test_invalid_session_token_rejected_with_401_session_expired(): void
    {
        $response = $this->withHeader('X-Session-Token', 'totally-invalid-token')
            ->postJson('/api/catalog/bulk-update', [
                'product_ids' => [1],
                'active' => false,
            ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error_code', 'SESSION_EXPIRED');
    }

    public function test_cashier_without_permission_and_without_pin_rejected_with_403_pin_required(): void
    {
        $this->actingAsCashierWithUser(permissions: []);

        $p = Product::create([
            'name' => 'Sec Prod 1',
            'internal_code' => 'SP1',
            'cost_price' => 10,
            'selling_price' => 20,
            'stock' => 5,
        ]);

        $response = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => [$p->id],
            'active' => false,
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error_code', 'PIN_REQUIRED');
    }

    public function test_cashier_without_permission_and_invalid_pin_rejected_with_403_invalid_admin_pin(): void
    {
        $this->actingAsCashierWithUser(permissions: []);

        $p = Product::create([
            'name' => 'Sec Prod 2',
            'internal_code' => 'SP2',
            'cost_price' => 10,
            'selling_price' => 20,
            'stock' => 5,
        ]);

        $response = $this->withHeader('X-Admin-Pin', '9999')
            ->postJson('/api/catalog/bulk-update', [
                'product_ids' => [$p->id],
                'active' => false,
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error_code', 'INVALID_ADMIN_PIN');
    }

    public function test_cashier_with_manage_catalog_permission_succeeds_without_pin(): void
    {
        $this->actingAsCashierWithUser(permissions: [Permissions::MANAGE_CATALOG]);

        $p = Product::create([
            'name' => 'Sec Prod 3',
            'internal_code' => 'SP3',
            'cost_price' => 10,
            'selling_price' => 20,
            'stock' => 5,
            'active' => true,
        ]);

        $response = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => [$p->id],
            'active' => false,
        ]);

        $response->assertStatus(200);
        $p->refresh();
        $this->assertFalse($p->active);
    }

    public function test_cashier_with_valid_admin_pin_in_body_is_authorized(): void
    {
        $this->actingAsCashierWithUser(permissions: []);

        $p = Product::create([
            'name' => 'Sec Prod 4',
            'internal_code' => 'SP4',
            'cost_price' => 10,
            'selling_price' => 20,
            'stock' => 5,
            'active' => true,
        ]);

        // Sending PIN in body 'admin_pin' instead of header
        $response = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => [$p->id],
            'active' => false,
            'admin_pin' => '1234',
        ]);

        $response->assertStatus(200);
        $p->refresh();
        $this->assertFalse($p->active);
    }

    /*
     |--------------------------------------------------------------------------
     | 2. FOREIGN KEY CLEARING & INVALID VALUE CHALLENGES
     |--------------------------------------------------------------------------
     */

    public function test_clearing_all_foreign_keys_to_null_simultaneously(): void
    {
        $this->actingAsAdmin($this->admin);

        $cat = Category::create(['name' => 'FK Category']);
        $brand = Brand::create(['name' => 'FK Brand']);
        $sup = Supplier::create(['name' => 'FK Sup', 'cuit' => '30-44556677-8']);

        $p = Product::create([
            'name' => 'All FKs Prod',
            'internal_code' => 'AFK1',
            'cost_price' => 10,
            'selling_price' => 20,
            'stock' => 5,
            'category_id' => $cat->id,
            'brand_id' => $brand->id,
            'supplier_id' => $sup->id,
        ]);

        $this->assertNotNull($p->category_id);
        $this->assertNotNull($p->brand_id);
        $this->assertNotNull($p->supplier_id);

        $response = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => [$p->id],
            'category_id' => null,
            'brand_id' => null,
            'supplier_id' => null,
        ]);

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('updated_count'));

        $p->refresh();
        $this->assertNull($p->category_id);
        $this->assertNull($p->brand_id);
        $this->assertNull($p->supplier_id);
    }

    public function test_negative_foreign_key_minus_one_rejected_with_422(): void
    {
        $this->actingAsAdmin($this->admin);

        $p = Product::create([
            'name' => 'Minus One Prod',
            'internal_code' => 'MO1',
            'cost_price' => 10,
            'selling_price' => 20,
            'stock' => 5,
        ]);

        // category_id = -1
        $resCat = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => [$p->id],
            'category_id' => -1,
        ]);
        $resCat->assertStatus(422)->assertJsonValidationErrors('category_id');

        // brand_id = -1
        $resBrand = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => [$p->id],
            'brand_id' => -1,
        ]);
        $resBrand->assertStatus(422)->assertJsonValidationErrors('brand_id');

        // supplier_id = -1
        $resSup = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => [$p->id],
            'supplier_id' => -1,
        ]);
        $resSup->assertStatus(422)->assertJsonValidationErrors('supplier_id');
    }

    public function test_zero_foreign_key_rejected_with_422(): void
    {
        $this->actingAsAdmin($this->admin);

        $p = Product::create([
            'name' => 'Zero FK Prod',
            'internal_code' => 'ZF1',
            'cost_price' => 10,
            'selling_price' => 20,
            'stock' => 5,
        ]);

        $resCat = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => [$p->id],
            'category_id' => 0,
        ]);
        $resCat->assertStatus(422)->assertJsonValidationErrors('category_id');

        $resBrand = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => [$p->id],
            'brand_id' => 0,
        ]);
        $resBrand->assertStatus(422)->assertJsonValidationErrors('brand_id');
    }

    public function test_clearing_single_foreign_key_preserves_other_foreign_keys(): void
    {
        $this->actingAsAdmin($this->admin);

        $cat = Category::create(['name' => 'Preserved Category']);
        $brand = Brand::create(['name' => 'Cleared Brand']);

        $p = Product::create([
            'name' => 'Preserve Prod',
            'internal_code' => 'PP1',
            'cost_price' => 10,
            'selling_price' => 20,
            'stock' => 5,
            'category_id' => $cat->id,
            'brand_id' => $brand->id,
        ]);

        // Only clear brand_id
        $response = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => [$p->id],
            'brand_id' => null,
        ]);

        $response->assertStatus(200);

        $p->refresh();
        $this->assertNull($p->brand_id);
        $this->assertEquals($cat->id, $p->category_id); // Category remains intact
    }

    /*
     |--------------------------------------------------------------------------
     | 3. CONCURRENCY & INTERLEAVED UPDATES SIMULATION
     |--------------------------------------------------------------------------
     */

    public function test_interleaved_updates_preserve_field_isolation(): void
    {
        $this->actingAsAdmin($this->admin);

        $cat = Category::create(['name' => 'Concur Cat']);
        $brand = Brand::create(['name' => 'Concur Brand']);

        $p1 = Product::create(['name' => 'C P1', 'internal_code' => 'CP1', 'cost_price' => 10, 'selling_price' => 20, 'stock' => 5]);
        $p2 = Product::create(['name' => 'C P2', 'internal_code' => 'CP2', 'cost_price' => 15, 'selling_price' => 30, 'stock' => 5]);
        $p3 = Product::create(['name' => 'C P3', 'internal_code' => 'CP3', 'cost_price' => 20, 'selling_price' => 40, 'stock' => 5]);

        // Worker A updates brand on [p1, p2]
        $resA = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => [$p1->id, $p2->id],
            'brand_id' => $brand->id,
        ]);
        $resA->assertStatus(200);

        // Worker B updates category on [p2, p3]
        $resB = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => [$p2->id, $p3->id],
            'category_id' => $cat->id,
        ]);
        $resB->assertStatus(200);

        $p1->refresh();
        $p2->refresh();
        $p3->refresh();

        // P1 has Brand, null Category
        $this->assertEquals($brand->id, $p1->brand_id);
        $this->assertNull($p1->category_id);

        // P2 has Brand AND Category (neither overwritten)
        $this->assertEquals($brand->id, $p2->brand_id);
        $this->assertEquals($cat->id, $p2->category_id);

        // P3 has null Brand, Category
        $this->assertNull($p3->brand_id);
        $this->assertEquals($cat->id, $p3->category_id);
    }

    /*
     |--------------------------------------------------------------------------
     | 4. MASSIVE BATCH CHUNKING & TRANSACTION INTEGRITY ON CHUNK 3
     |--------------------------------------------------------------------------
     */

    public function test_transaction_rollback_across_three_chunks(): void
    {
        $this->actingAsAdmin($this->admin);

        // Create 1005 products -> 3 chunks: Chunk 1 (500), Chunk 2 (500), Chunk 3 (5)
        $records = [];
        $now = now();
        for ($i = 1; $i <= 1005; $i++) {
            $records[] = [
                'name' => "Stress Prod $i",
                'internal_code' => "STR{$i}",
                'cost_price' => 10,
                'selling_price' => 20,
                'stock' => 5,
                'active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        Product::insert($records);
        $ids = Product::where('internal_code', 'like', 'STR%')->pluck('id')->toArray();
        $this->assertCount(1005, $ids);

        // Simulate failure on the 3rd chunk
        $updateQueryCount = 0;
        DB::listen(function ($query) use (&$updateQueryCount) {
            if (str_contains(strtolower($query->sql), 'update "products"') || str_contains(strtolower($query->sql), 'update `products`')) {
                $updateQueryCount++;
                if ($updateQueryCount === 3) {
                    throw new \RuntimeException('Simulated failure during 3rd chunk processing');
                }
            }
        });

        $response = $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => $ids,
            'active' => false,
        ]);

        $response->assertStatus(500);
        $response->assertJson([
            'message' => 'Ocurrió un error al procesar la actualización masiva de productos.',
        ]);

        // Invariant: None of the 1005 products may be left with active = 0
        $activeCount = Product::where('internal_code', 'like', 'STR%')->where('active', 1)->count();
        $this->assertEquals(1005, $activeCount, 'Atomic rollback failed: partial chunks were committed!');
    }

    /*
     |--------------------------------------------------------------------------
     | 5. BOOLEAN CASTING & EDGE VALUE PERMUTATIONS
     |--------------------------------------------------------------------------
     */

    public function test_boolean_active_variations(): void
    {
        $this->actingAsAdmin($this->admin);

        $p = Product::create([
            'name' => 'Bool Prod',
            'internal_code' => 'BL1',
            'cost_price' => 10,
            'selling_price' => 20,
            'stock' => 5,
            'active' => true,
        ]);

        // Integer 0 -> false
        $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => [$p->id],
            'active' => 0,
        ])->assertStatus(200);
        $p->refresh();
        $this->assertFalse($p->active);

        // Integer 1 -> true
        $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => [$p->id],
            'active' => 1,
        ])->assertStatus(200);
        $p->refresh();
        $this->assertTrue($p->active);

        // Explicit boolean false
        $this->postJson('/api/catalog/bulk-update', [
            'product_ids' => [$p->id],
            'active' => false,
        ])->assertStatus(200);
        $p->refresh();
        $this->assertFalse($p->active);
    }
}
