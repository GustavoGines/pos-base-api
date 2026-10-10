<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EcommerceIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EcommerceModelMutatorSecurityEmpiricalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test 1: Mass-assignment via constructor encrypts sensitive attributes into raw ciphertext.
     */
    public function test_mass_assignment_constructor_encrypts_attributes_into_ciphertext(): void
    {
        $plainToken = 'test_access_token_emp_999';
        $plainSecret = 'test_client_secret_emp_888';
        $plainRefresh = 'test_refresh_token_emp_777';
        $plainWebhook = 'test_webhook_secret_emp_666';

        $integration = new EcommerceIntegration([
            'platform' => 'mercadolibre',
            'name' => 'Empirical Meli Store',
            'access_token' => $plainToken,
            'client_secret' => $plainSecret,
            'refresh_token' => $plainRefresh,
            'webhook_secret' => $plainWebhook,
        ]);

        $attributes = $integration->getAttributes();

        // Verify raw attributes are NOT plain text
        $this->assertNotEquals($plainToken, $attributes['access_token'], 'access_token raw attribute must not be plaintext');
        $this->assertNotEquals($plainSecret, $attributes['client_secret'], 'client_secret raw attribute must not be plaintext');
        $this->assertNotEquals($plainRefresh, $attributes['refresh_token'], 'refresh_token raw attribute must not be plaintext');
        $this->assertNotEquals($plainWebhook, $attributes['webhook_secret'], 'webhook_secret raw attribute must not be plaintext');

        // Verify raw attributes are valid ciphertexts decryptable by Crypt
        $this->assertEquals($plainToken, Crypt::decryptString($attributes['access_token']));
        $this->assertEquals($plainSecret, Crypt::decryptString($attributes['client_secret']));
        $this->assertEquals($plainRefresh, Crypt::decryptString($attributes['refresh_token']));
        $this->assertEquals($plainWebhook, Crypt::decryptString($attributes['webhook_secret']));

        // Verify raw attributes do not leak plaintext substrings
        $this->assertStringNotContainsString($plainToken, $attributes['access_token']);
        $this->assertStringNotContainsString($plainSecret, $attributes['client_secret']);
        $this->assertStringNotContainsString($plainRefresh, $attributes['refresh_token']);
        $this->assertStringNotContainsString($plainWebhook, $attributes['webhook_secret']);
    }

    /**
     * Test 2: Mass-assignment via create() persists raw ciphertext to DB.
     */
    public function test_mass_assignment_create_persists_ciphertext_to_database(): void
    {
        $plainToken = 'meli_db_persisted_token_555';

        $integration = EcommerceIntegration::create([
            'platform' => 'mercadolibre',
            'name' => 'Persisted Store',
            'access_token' => $plainToken,
        ]);

        $rawDbValue = DB::table('ecommerce_integrations')
            ->where('id', $integration->id)
            ->value('access_token');

        $this->assertNotNull($rawDbValue);
        $this->assertNotEquals($plainToken, $rawDbValue);
        $this->assertEquals($plainToken, Crypt::decryptString($rawDbValue));
        $this->assertEquals($plainToken, $integration->decrypted_access_token);
    }

    /**
     * Test 3: Updating with masked values '****' preserves existing ciphertext without corruption.
     */
    public function test_update_with_masked_value_preserves_existing_ciphertext(): void
    {
        $originalToken = 'live_production_token_abc_123';

        $integration = EcommerceIntegration::create([
            'platform' => 'tiendanube',
            'name' => 'Tienda Test Mask',
            'access_token' => $originalToken,
        ]);

        $initialCiphertext = DB::table('ecommerce_integrations')
            ->where('id', $integration->id)
            ->value('access_token');

        $this->assertNotNull($initialCiphertext);

        // Scenario A: Update using update() with '****'
        $integration->update([
            'access_token' => '****',
            'name' => 'Tienda Test Mask Updated Name',
        ]);

        $persistedAfterUpdate = DB::table('ecommerce_integrations')
            ->where('id', $integration->id)
            ->value('access_token');

        $this->assertSame(
            $initialCiphertext,
            $persistedAfterUpdate,
            'Ciphertext in DB must be exactly unchanged after update with ****'
        );
        $this->assertEquals(
            $originalToken,
            $integration->decrypted_access_token,
            'Decrypted accessor must still return original token'
        );

        // Scenario B: Re-query from database into fresh model instance and update with partial mask
        $freshIntegration = EcommerceIntegration::findOrFail($integration->id);
        $freshIntegration->update([
            'access_token' => 'live****_123',
        ]);

        $persistedAfterPartialMask = DB::table('ecommerce_integrations')
            ->where('id', $integration->id)
            ->value('access_token');

        $this->assertSame(
            $initialCiphertext,
            $persistedAfterPartialMask,
            'Ciphertext in DB must be exactly unchanged after update with partial mask'
        );
        $this->assertEquals(
            $originalToken,
            $freshIntegration->decrypted_access_token,
            'Decrypted accessor on fresh instance must still return original token'
        );

        // Scenario C: Legitimate new token update replaces ciphertext
        $newToken = 'brand_new_token_def_456';
        $freshIntegration->update([
            'access_token' => $newToken,
        ]);

        $persistedNewCiphertext = DB::table('ecommerce_integrations')
            ->where('id', $integration->id)
            ->value('access_token');

        $this->assertNotEquals($initialCiphertext, $persistedNewCiphertext);
        $this->assertEquals($newToken, Crypt::decryptString($persistedNewCiphertext));
        $this->assertEquals($newToken, $freshIntegration->decrypted_access_token);
    }

    /**
     * Test 4: Decryption accessors yield exact plaintext for all sensitive fields.
     */
    public function test_decryption_accessors_yield_plaintext_for_all_sensitive_fields(): void
    {
        $fields = [
            'client_secret' => 'secret_val_111',
            'access_token' => 'token_val_222',
            'refresh_token' => 'refresh_val_333',
            'webhook_secret' => 'webhook_val_444',
        ];

        $integration = new EcommerceIntegration(array_merge([
            'platform' => 'woocommerce',
            'name' => 'Woo Store Accessors',
        ], $fields));

        $this->assertEquals('secret_val_111', $integration->decrypted_client_secret);
        $this->assertEquals('token_val_222', $integration->decrypted_access_token);
        $this->assertEquals('refresh_val_333', $integration->decrypted_refresh_token);
        $this->assertEquals('webhook_val_444', $integration->decrypted_webhook_secret);
    }

    /**
     * Test 5: Edge cases on decryption accessors and mutators (null, empty string, corrupted ciphertext).
     */
    public function test_decryption_edge_cases(): void
    {
        $integration = new EcommerceIntegration;

        // Null attributes
        $this->assertNull($integration->decrypted_access_token);
        $this->assertNull($integration->decrypted_client_secret);
        $this->assertNull($integration->decrypted_refresh_token);
        $this->assertNull($integration->decrypted_webhook_secret);

        // Setting null explicitly
        $integration->access_token = null;
        $this->assertNull($integration->decrypted_access_token);
        $this->assertNull($integration->getAttributes()['access_token'] ?? null);

        // Setting empty string explicitly
        $integration->access_token = '';
        $this->assertNull($integration->decrypted_access_token);
        $this->assertNull($integration->getAttributes()['access_token'] ?? null);

        // Corrupted ciphertext in attributes (e.g. invalid base64 or changed MAC)
        $integration->setRawAttributes(['access_token' => 'corrupted_payload_not_valid_json_or_mac']);
        $this->assertNull($integration->decrypted_access_token, 'Decryption failure should gracefully return null');
    }

    /**
     * Test 6: Direct property assignment ($model->access_token = 'token') routes through mutator.
     */
    public function test_direct_property_assignment_routes_through_mutator(): void
    {
        $integration = new EcommerceIntegration;
        $integration->access_token = 'plain_direct_assignment_token';

        $rawAttribute = $integration->getAttributes()['access_token'];
        $this->assertNotEquals('plain_direct_assignment_token', $rawAttribute);
        $this->assertEquals('plain_direct_assignment_token', Crypt::decryptString($rawAttribute));
        $this->assertEquals('plain_direct_assignment_token', $integration->decrypted_access_token);
    }
}
