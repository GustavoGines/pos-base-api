<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdversarialQuoteDeadlockRetryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'admin']);
        $this->actingAsAdmin($this->user);

        BusinessSetting::create([
            'key' => 'license_features_dict',
            'value' => json_encode(['quotes' => true]),
        ]);
    }

    /**
     * Helper to create a QueryException with custom SQLSTATE and message
     */
    private function createQueryException(string $sqlState, int $errorCode, string $errorMessage): QueryException
    {
        $pdoException = new \PDOException($errorMessage, (int) $errorCode);
        $pdoException->errorInfo = [$sqlState, $errorCode, $errorMessage];

        return new QueryException('mysql', 'INSERT INTO quotes ...', [], $pdoException);
    }

    /**
     * Challenge 1: Simulated MySQL InnoDB deadlock (SQLSTATE 40001, error 1213)
     * triggers retry and succeeds on second attempt.
     */
    public function test_deadlock_40001_1213_triggers_retry_and_succeeds_on_next_attempt(): void
    {
        $attempts = 0;
        Quote::creating(function ($quote) use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                throw $this->createQueryException(
                    '40001',
                    1213,
                    'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'
                );
            }
        });

        $response = $this->postJson('/api/quotes', [
            'customer_name' => 'Cliente Deadlock Test',
            'items' => [
                ['product_name' => 'Item Test', 'unit_price' => 100.0, 'quantity' => 2],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertEquals(2, $attempts, 'Quote creation must have retried after the first deadlock');
        $this->assertEquals('PRES-0001', $response->json('quote_number'));
        $this->assertDatabaseHas('quotes', ['quote_number' => 'PRES-0001']);
    }

    /**
     * Challenge 2: Multiple consecutive deadlocks up to attempt 4 resolve cleanly on attempt 5.
     */
    public function test_multiple_consecutive_deadlocks_resolve_on_fifth_attempt(): void
    {
        $attempts = 0;
        Quote::creating(function ($quote) use (&$attempts) {
            $attempts++;
            if ($attempts < 5) {
                throw $this->createQueryException(
                    '40001',
                    1213,
                    "SQLSTATE[40001]: Serialization failure: 1213 Deadlock found on attempt {$attempts}"
                );
            }
        });

        $response = $this->postJson('/api/quotes', [
            'customer_name' => 'Cliente Multi-Deadlock',
            'items' => [
                ['product_name' => 'Item Multi', 'unit_price' => 50.0, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertEquals(5, $attempts, 'Quote creation must have retried 4 times and succeeded on attempt 5');
        $this->assertEquals('PRES-0001', $response->json('quote_number'));
    }

    /**
     * Challenge 3: Deadlocks exhausting all 5 attempts fail gracefully with HTTP 500
     * and clean rollback without leaving orphaned records.
     */
    public function test_deadlocks_exhausting_max_attempts_fail_gracefully_with_http_500(): void
    {
        $attempts = 0;
        Quote::creating(function ($quote) use (&$attempts) {
            $attempts++;
            throw $this->createQueryException(
                '40001',
                1213,
                'SQLSTATE[40001]: Serialization failure: 1213 Persistent deadlock'
            );
        });

        $response = $this->postJson('/api/quotes', [
            'customer_name' => 'Cliente Exhausted',
            'items' => [
                ['product_name' => 'Item Fail', 'unit_price' => 50.0, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(500);
        $this->assertEquals(5, $attempts, 'Must have attempted exactly maxAttempts (5) before giving up');
        $response->assertJsonStructure(['message']);
        $this->assertStringContainsStringIgnoringCase('deadlock', $response->json('message'));
        $this->assertEquals(0, Quote::count(), 'Database transaction must be rolled back, leaving 0 records');
    }

    /**
     * Challenge 4: Non-retryable database exception (e.g. SQL syntax error, table not found)
     * does NOT enter retry loop and fails immediately on attempt 1.
     */
    public function test_non_retryable_query_exception_fails_immediately_without_retrying(): void
    {
        $attempts = 0;
        Quote::creating(function ($quote) use (&$attempts) {
            $attempts++;
            throw $this->createQueryException(
                '42S02',
                1146,
                "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'quotes' doesn't exist"
            );
        });

        $response = $this->postJson('/api/quotes', [
            'customer_name' => 'Cliente Non-Retryable',
            'items' => [
                ['product_name' => 'Item X', 'unit_price' => 10.0, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(500);
        $this->assertEquals(1, $attempts, 'Non-retryable error must fail immediately on attempt 1');
    }

    /**
     * Challenge 5: Serialization failure without explicit 1213 code string matches
     * and triggers retry.
     */
    public function test_serialization_failure_string_match_triggers_retry(): void
    {
        $attempts = 0;
        Quote::creating(function ($quote) use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                throw $this->createQueryException(
                    '40001',
                    0,
                    'SQLSTATE[40001]: Serialization failure: could not serialize access'
                );
            }
        });

        $response = $this->postJson('/api/quotes', [
            'customer_name' => 'Cliente Serialization String',
            'items' => [
                ['product_name' => 'Item Test', 'unit_price' => 30.0, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertEquals(2, $attempts);
    }

    /**
     * Challenge 6: Duplicate entry (23000) collision triggers retry and succeeds on next attempt.
     */
    public function test_duplicate_entry_23000_triggers_retry(): void
    {
        $attempts = 0;
        Quote::creating(function ($quote) use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                throw $this->createQueryException(
                    '23000',
                    1062,
                    "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'PRES-0001' for key 'quotes_quote_number_unique'"
                );
            }
        });

        $response = $this->postJson('/api/quotes', [
            'customer_name' => 'Cliente Duplicate Key',
            'items' => [
                ['product_name' => 'Item Test', 'unit_price' => 30.0, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertEquals(2, $attempts);
    }
}
