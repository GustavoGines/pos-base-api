<?php

namespace Tests\Feature;

use App\Console\Commands\SyncLicenseCommand;
use App\Console\Commands\SyncLicenseStatus;
use App\Events\SaleCompleted;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PhaseP1SecurityAndIntegrityTest
 *
 * Automated verification suite for Phase P1 (Emergencias y Seguridad Operacional):
 * - P1.1 (DEBT-01/FIN-06): Refund customer transactions and MySQL enum compatibility
 * - P1.2 (DEBT-02/FIN-07): Shift and cashier updated when paying pending sales
 * - P1.3 (FIN-04): Persist price_list on Sale model
 * - P1.6 (DEBT-08/SEC-07): Strict file upload validation and anti-RCE .htaccess
 * - P1.7 (DEBT-09/10/SEC-08/09): Protected /customers, /sales, /sales/pending and removed /system/install-path
 * - P1.8 (DEBT-11/SEC-05): Fail-secure rescue-migrate endpoint
 * - P1.9 (ROU-02): SaleCompleted real-time WebSocket broadcasting contract
 * - P1.10 (ARC-04): Resolved Artisan license:sync command collision
 */
class PhaseP1SecurityAndIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable feature flags required for modules under test
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'license_features_dict'],
            ['value' => json_encode([
                'suppliers' => true,
                'quotes' => true,
                'checks' => true,
            ])]
        );
    }

    /**
     * Helper to create payment method cash
     */
    private function createCashMethod(): PaymentMethod
    {
        return PaymentMethod::firstOrCreate(
            ['code' => 'efectivo'],
            ['name' => 'Efectivo', 'is_cash' => true, 'is_active' => true]
        );
    }

    /**
     * P1.1 / DEBT-01 / FIN-06:
     * Validates that customer refund transactions are allowed and correctly update customer credit balance.
     */
    public function test_refund_transaction_type_allowed_and_updates_balance(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $admin);
        $this->createCashMethod();

        // Customer with a credit balance (-500 means customer is owed $500)
        $customer = Customer::create([
            'name' => 'Cliente Saldo a Favor',
            'document_type' => 'DNI',
            'document_number' => '44556677',
            'balance' => -500.00,
        ]);

        $this->actingAsAdmin($admin);

        // Process refund of $200
        $response = $this->postJson("/api/customers/{$customer->id}/payments", [
            'amount' => 200.00,
            'is_refund' => true,
            'payment_method' => 'cash',
            'cash_shift_id' => $shift->id,
        ]);

        $response->assertStatus(200);

        // Assert customer transaction was created with type 'refund'
        $this->assertDatabaseHas('customer_transactions', [
            'customer_id' => $customer->id,
            'type' => 'refund',
            'amount' => 200.00,
            'payment_method' => 'cash',
        ]);

        // Customer balance should be updated: -500 + 200 = -300
        $this->assertEquals(-300.00, (float) $customer->fresh()->balance);

        // Reject refund if customer has no credit balance (balance >= 0)
        $customerNoCredit = Customer::create([
            'name' => 'Cliente Sin Saldo a Favor',
            'document_type' => 'DNI',
            'document_number' => '88990011',
            'balance' => 0.00,
        ]);

        $rejectResponse = $this->postJson("/api/customers/{$customerNoCredit->id}/payments", [
            'amount' => 50.00,
            'is_refund' => true,
            'payment_method' => 'cash',
            'cash_shift_id' => $shift->id,
        ]);

        $rejectResponse->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    /**
     * P1.2 / DEBT-02 / FIN-07:
     * Validates that paying a pending sale updates cash_shift_id and cashier_id to the active cashier and shift.
     */
    public function test_pending_sale_pay_assigns_shift_and_cashier(): void
    {
        $cashier1 = User::factory()->create(['role' => 'cashier']);
        $shift1 = $this->crearTurnoAbierto(fondoInicial: 1000, user: $cashier1);

        $cashier2 = User::factory()->create(['role' => 'cashier']);
        $register2 = CashRegister::firstOrCreate(['id' => 2], ['name' => 'Caja Secundaria', 'is_active' => true]);
        $shift2 = CashShift::create([
            'cash_register_id' => $register2->id,
            'user_id' => $cashier2->id,
            'opened_at' => now(),
            'opening_balance' => 500,
            'status' => 'open',
        ]);

        $cashMethod = $this->createCashMethod();
        $product = Product::create([
            'name' => 'Producto Diferido',
            'internal_code' => 'PDIF01',
            'selling_price' => 500.00,
            'cost_price' => 250.00,
            'stock' => 20,
            'active' => true,
        ]);

        // Sale opened in shift 1 by cashier 1 as pending
        $sale = Sale::create([
            'total' => 500.00,
            'total_surcharge' => 0,
            'payment_status' => 'pending',
            'amount_due' => 500.00,
            'status' => 'pending',
            'cash_shift_id' => $shift1->id,
            'user_id' => $cashier1->id,
            'cashier_id' => $cashier1->id,
        ]);

        $sale->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_cost_price' => 250.00,
            'unit_price' => 500.00,
            'subtotal' => 500.00,
        ]);

        // Cashier 2 collects payment in shift 2
        $tokenCashier2 = 'token-cashier-2-'.uniqid();
        $cashier2->update(['session_token' => $tokenCashier2]);

        $response = $this->withHeader('X-Session-Token', $tokenCashier2)
            ->putJson("/api/sales/{$sale->id}/pay", [
                'total' => 500.00,
                'total_surcharge' => 0,
                'cash_shift_id' => $shift2->id,
                'payments' => [
                    [
                        'payment_method_id' => $cashMethod->id,
                        'base_amount' => 500.00,
                        'surcharge_amount' => 0,
                        'total_amount' => 500.00,
                    ],
                ],
                'tendered_amount' => 500.00,
                'change_amount' => 0,
            ]);

        $response->assertStatus(200);

        // Verify sale is updated with cashier 2 and shift 2
        $freshSale = $sale->fresh();
        $this->assertEquals('completed', $freshSale->status);
        $this->assertEquals($shift2->id, $freshSale->cash_shift_id);
        $this->assertEquals($cashier2->id, $freshSale->cashier_id);
    }

    /**
     * P1.3 / FIN-04:
     * Validates that price_list is persisted on the Sale model.
     */
    public function test_sale_model_persists_price_list(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = $this->crearTurnoAbierto(user: $admin);
        $cashMethod = $this->createCashMethod();

        $product = Product::create([
            'name' => 'Producto Mayorista',
            'internal_code' => 'PMAY01',
            'selling_price' => 80.00,
            'cost_price' => 40.00,
            'stock' => 50,
            'active' => true,
        ]);

        $payload = [
            'total' => 160.00,
            'total_surcharge' => 0,
            'cash_shift_id' => $shift->id,
            'user_id' => $admin->id,
            'price_list' => 'mayorista_especial',
            'payments' => [[
                'payment_method_id' => $cashMethod->id,
                'base_amount' => 160.00,
                'surcharge_amount' => 0,
                'total_amount' => 160.00,
            ]],
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_price' => 80.00,
                'subtotal' => 160.00,
            ]],
        ];

        $response = $this->actingAsAdmin($admin)
            ->postJson('/api/pos/sales', $payload);

        $response->assertStatus(201);

        $latestSale = Sale::latest('id')->first();
        $this->assertNotNull($latestSale);
        $this->assertEquals('mayorista_especial', $latestSale->price_list);
    }

    /**
     * P1.6 / DEBT-08 / SEC-07:
     * Validates file upload security in SupplierInvoiceController:
     * Rejects dangerous file types (.php, .sh) and accepts allowed documents (.pdf, .jpg).
     */
    public function test_supplier_invoice_upload_rejects_non_whitelisted_files_and_accepts_valid(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAsAdmin($admin);

        // 1. Attempt uploading executable .php script
        $phpFile = UploadedFile::fake()->create('backdoor.php', 10, 'application/x-php');
        $resPhp = $this->postJson('/api/supplier-invoices/upload', ['file' => $phpFile]);
        $resPhp->assertStatus(422)
            ->assertJsonValidationErrors(['file']);

        // 2. Attempt uploading shell script .sh
        $shFile = UploadedFile::fake()->create('exploit.sh', 10, 'text/x-shellscript');
        $resSh = $this->postJson('/api/supplier-invoices/upload', ['file' => $shFile]);
        $resSh->assertStatus(422)
            ->assertJsonValidationErrors(['file']);

        // 3. Upload valid PDF
        $pdfFile = UploadedFile::fake()->create('factura_proveedor.pdf', 200, 'application/pdf');
        $resPdf = $this->postJson('/api/supplier-invoices/upload', ['file' => $pdfFile]);
        $resPdf->assertStatus(200)
            ->assertJsonStructure(['message', 'file_url']);

        $fileUrl = $resPdf->json('file_url');
        $this->assertStringStartsWith('/storage/supplier_invoices/', $fileUrl);
        $this->assertStringEndsWith('.pdf', $fileUrl);
        // Assert filename was sanitized and is not literal 'factura_proveedor.pdf'
        $this->assertStringNotContainsString('factura_proveedor.pdf', $fileUrl);

        // 4. Upload valid JPEG image
        $jpgFile = UploadedFile::fake()->image('recibo.jpg');
        $resJpg = $this->postJson('/api/supplier-invoices/upload', ['file' => $jpgFile]);
        $resJpg->assertStatus(200)
            ->assertJsonStructure(['message', 'file_url']);
    }

    /**
     * P1.6 Anti-RCE Defense:
     * Verifies that storage/app/public/.htaccess exists and blocks execution of script extensions.
     */
    public function test_storage_public_htaccess_blocks_script_execution(): void
    {
        $htaccessPath = storage_path('app/public/.htaccess');
        $this->assertFileExists($htaccessPath);

        $content = file_get_contents($htaccessPath);
        $this->assertStringContainsString('FilesMatch', $content);
        $this->assertStringContainsString('php', $content);
        $this->assertStringContainsString('Deny from all', $content);
    }

    /**
     * P1.7 / DEBT-09 / DEBT-10 / SEC-08 / SEC-09:
     * Validates that /customers, /sales, and /sales/pending require authentication,
     * and that /system/install-path is removed (returns 404).
     */
    public function test_customer_and_sales_routes_require_session_and_install_path_is_404(): void
    {
        // 1. Public calls without token must return 401
        $this->getJson('/api/customers')->assertStatus(401);
        $this->getJson('/api/sales')->assertStatus(401);
        $this->getJson('/api/sales/pending')->assertStatus(401);

        // 2. /system/install-path must return 404
        $this->getJson('/api/system/install-path')->assertStatus(404);

        // 3. With valid session token, access is granted
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAsAdmin($admin);

        $this->getJson('/api/customers')->assertStatus(200);
        $this->getJson('/api/sales')->assertStatus(200);
        $this->getJson('/api/sales/pending')->assertStatus(200);
    }

    /**
     * P1.8 / DEBT-11 / SEC-05:
     * Validates that /system/rescue-migrate is fail-secure.
     * Aborts with 403 if secret is empty or token mismatch.
     */
    public function test_rescue_migrate_endpoint_is_fail_secure(): void
    {
        // Scenario A: Secret is not configured (empty or null) -> must reject with 403
        config(['app.rescue_migrate_secret' => null]);
        $this->getJson('/api/system/rescue-migrate')->assertStatus(403);
        $this->withHeader('X-Rescue-Token', 'some-token')
            ->getJson('/api/system/rescue-migrate')
            ->assertStatus(403);

        // Scenario B: Secret is configured, but no header is sent -> must reject with 403
        config(['app.rescue_migrate_secret' => 'pos-secure-rescue-token-2026']);
        $this->getJson('/api/system/rescue-migrate')->assertStatus(403);

        // Scenario C: Secret is configured, but invalid token sent -> must reject with 403
        $this->withHeader('X-Rescue-Token', 'invalid-token-guess')
            ->getJson('/api/system/rescue-migrate')
            ->assertStatus(403);

        // Scenario D: Secret is configured and exact token is sent -> authorized 200
        $this->withHeader('X-Rescue-Token', 'pos-secure-rescue-token-2026')
            ->getJson('/api/system/rescue-migrate')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    /**
     * P1.9 / ROU-02:
     * Validates that SaleCompleted implements ShouldBroadcastNow and broadcasts on channel 'dashboard'.
     */
    public function test_sale_completed_event_broadcasting_contract(): void
    {
        $this->assertTrue(
            is_subclass_of(SaleCompleted::class, ShouldBroadcastNow::class) ||
            in_array(ShouldBroadcastNow::class, class_implements(SaleCompleted::class) ?: []),
            'SaleCompleted must implement ShouldBroadcastNow'
        );

        $dummySale = new Sale(['id' => 999, 'total' => 100]);
        $event = new SaleCompleted($dummySale);

        $channels = $event->broadcastOn();
        $this->assertNotEmpty($channels);
        $this->assertInstanceOf(Channel::class, $channels[0]);
        $this->assertEquals('dashboard', $channels[0]->name);

        $this->assertEquals('App\\Events\\DashboardUpdated', $event->broadcastAs());
    }

    /**
     * P1.10 / ARC-04:
     * Validates that the Artisan command signature collision is resolved and 'license:sync'
     * unambiguously points to SyncLicenseCommand.
     */
    public function test_artisan_license_sync_command_resolution(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('license:sync', $commands);
        $this->assertInstanceOf(SyncLicenseCommand::class, $commands['license:sync']);

        $this->assertArrayHasKey('license:sync-status', $commands);
        $this->assertInstanceOf(SyncLicenseStatus::class, $commands['license:sync-status']);
    }
}
