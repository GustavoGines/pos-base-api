<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\SaleContextDTO;
use App\Models\CashRegister;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\CustomerTransaction;
use App\Models\Sale;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * D-03: El plan de bloqueos de voidSale (CashShift / Customer) se calcula fuera de la transacción.
 * Si un cobro concurrente modifica la venta entre ese cálculo y el lock de Sale, la anulación debe
 * detectarlo, abortar sin tocar datos y recalcular el plan, en lugar de bloquear un Customer después
 * de Sale (inversión del orden canónico -> Deadlock 1213 en MySQL/InnoDB).
 *
 * SQLite no reproduce locks: estos tests verifican la detección y el recálculo, no el deadlock en sí.
 */
class VoidSaleLockPlanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CashShift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $register = CashRegister::create(['name' => 'Caja D-03', 'is_active' => true]);
        $this->shift = CashShift::create([
            'user_id' => $this->admin->id,
            'cash_register_id' => $register->id,
            'opening_balance' => 0,
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }

    private function createPendingSaleWithoutCustomer(): Sale
    {
        return Sale::create([
            'user_id' => $this->admin->id,
            'cash_shift_id' => $this->shift->id,
            'total' => 300.00,
            'payment_method' => 'cash',
            'status' => 'pending',
        ]);
    }

    /**
     * Ejecuta $onPlanQuery cada vez que voidSale lee los cargos de Cta Cte FUERA de su transacción
     * (pre-cálculo del plan), simulando una operación concurrente que confirma justo después.
     */
    private function onVoidLockPlanPrecalc(int $baseLevel, callable $onPlanQuery): void
    {
        $running = false;
        DB::listen(function (QueryExecuted $query) use ($baseLevel, $onPlanQuery, &$running) {
            if ($running || DB::transactionLevel() !== $baseLevel) {
                return;
            }
            $sql = strtolower($query->sql);
            if (! str_contains($sql, 'customer_transactions') || ! str_contains($sql, 'type')) {
                return;
            }

            $running = true;
            try {
                $onPlanQuery();
            } finally {
                $running = false;
            }
        });
    }

    private function context(): SaleContextDTO
    {
        return SaleContextDTO::fromArray([
            'user_id' => $this->admin->id,
            'cash_shift_id' => $this->shift->id,
        ], $this->admin->id);
    }

    public function test_d03_void_recalculates_lock_plan_when_concurrent_payment_assigns_customer(): void
    {
        $sale = $this->createPendingSaleWithoutCustomer();
        $customer = Customer::create(['name' => 'Cliente Concurrente', 'balance' => 0]);

        $planPrecalcs = 0;
        $this->onVoidLockPlanPrecalc(DB::transactionLevel(), function () use (&$planPrecalcs, $sale, $customer) {
            $planPrecalcs++;
            if ($planPrecalcs !== 1) {
                return;
            }

            // Cobro concurrente a Cuenta Corriente confirmado entre el pre-cálculo y el lock de Sale
            DB::table('sales')->where('id', $sale->id)->update([
                'status' => 'completed',
                'customer_id' => $customer->id,
            ]);
            DB::table('customers')->where('id', $customer->id)->update(['balance' => 300.00]);
            CustomerTransaction::create([
                'customer_id' => $customer->id,
                'user_id' => $this->admin->id,
                'sale_id' => $sale->id,
                'type' => 'charge',
                'amount' => 300.00,
                'balance_after' => 300.00,
                'description' => 'Cargo concurrente D-03',
            ]);
        });

        $voided = app(SaleService::class)->voidSale($sale, $this->context());

        $this->assertSame('voided', $voided->status);
        // El primer plan (sin cliente) quedó desactualizado: debe haberse recalculado una única vez
        $this->assertSame(2, $planPrecalcs);
        $this->assertEquals(0.00, (float) $customer->fresh()->balance);
        $this->assertDatabaseHas('customer_transactions', [
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'type' => 'payment',
        ]);
        // La reversión de Cta Cte se aplica una sola vez (el intento abortado no dejó rastros)
        $this->assertSame(1, CustomerTransaction::where('sale_id', $sale->id)->where('type', 'payment')->count());
    }

    public function test_d03_void_without_concurrent_changes_computes_lock_plan_once(): void
    {
        $sale = $this->createPendingSaleWithoutCustomer();

        $planPrecalcs = 0;
        $this->onVoidLockPlanPrecalc(DB::transactionLevel(), function () use (&$planPrecalcs) {
            $planPrecalcs++;
        });

        $voided = app(SaleService::class)->voidSale($sale, $this->context());

        $this->assertSame('voided', $voided->status);
        $this->assertSame(1, $planPrecalcs);
    }

    public function test_d03_void_gives_up_with_422_message_when_sale_keeps_changing(): void
    {
        $sale = $this->createPendingSaleWithoutCustomer();

        $planPrecalcs = 0;
        $this->onVoidLockPlanPrecalc(DB::transactionLevel(), function () use (&$planPrecalcs, $sale) {
            $planPrecalcs++;
            $customer = Customer::create(['name' => "Cliente Rotativo {$planPrecalcs}", 'balance' => 0]);
            DB::table('sales')->where('id', $sale->id)->update(['customer_id' => $customer->id]);
        });

        try {
            app(SaleService::class)->voidSale($sale, $this->context());
            $this->fail('Se esperaba InvalidArgumentException tras agotar los recálculos del plan de bloqueos.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('modificada por otra operación', $e->getMessage());
        }

        $this->assertSame(3, $planPrecalcs);
        $this->assertNotSame('voided', $sale->fresh()->status);
    }
}
