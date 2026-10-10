<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EcommerceIntegration;
use App\Services\Ecommerce\EcommerceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EcommerceSecurityChallengerTest extends TestCase
{
    use RefreshDatabase;

    protected EcommerceManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = app(EcommerceManager::class);
    }

    /**
     * Requirement 1: client_secret, access_token, refresh_token, and webhook_secret
     * are encrypted at rest with Crypt::encryptString.
     */
    public function test_sensitive_credentials_are_encrypted_with_crypt_encrypt_string(): void
    {
        $integration = new EcommerceIntegration([
            'platform' => 'mercadolibre',
            'name' => 'MELI Sec Test',
            'store_id' => 'sec_store_01',
        ]);

        $plainClientSecret = 'meli_client_secret_xyz123';
        $plainAccessToken = 'meli_access_token_abc456';
        $plainRefreshToken = 'meli_refresh_token_def789';
        $plainWebhookSecret = 'meli_webhook_secret_hmac999';

        $integration->setEncryptedSecret('client_secret', $plainClientSecret);
        $integration->setEncryptedSecret('access_token', $plainAccessToken);
        $integration->setEncryptedSecret('refresh_token', $plainRefreshToken);
        $integration->setEncryptedSecret('webhook_secret', $plainWebhookSecret);

        $attrs = $integration->getAttributes();

        // 1. None of the raw attributes should match plaintext
        $this->assertNotEquals($plainClientSecret, $attrs['client_secret']);
        $this->assertNotEquals($plainAccessToken, $attrs['access_token']);
        $this->assertNotEquals($plainRefreshToken, $attrs['refresh_token']);
        $this->assertNotEquals($plainWebhookSecret, $attrs['webhook_secret']);

        // 2. Crypt::decryptString should be able to decrypt each attribute to original plaintext
        $this->assertEquals($plainClientSecret, Crypt::decryptString($attrs['client_secret']));
        $this->assertEquals($plainAccessToken, Crypt::decryptString($attrs['access_token']));
        $this->assertEquals($plainRefreshToken, Crypt::decryptString($attrs['refresh_token']));
        $this->assertEquals($plainWebhookSecret, Crypt::decryptString($attrs['webhook_secret']));

        // 3. Decrypted accessors must return original plaintext
        $this->assertEquals($plainClientSecret, $integration->decrypted_client_secret);
        $this->assertEquals($plainAccessToken, $integration->decrypted_access_token);
        $this->assertEquals($plainRefreshToken, $integration->decrypted_refresh_token);
        $this->assertEquals($plainWebhookSecret, $integration->decrypted_webhook_secret);
    }

    /**
     * Requirement 2: Raw database column values are ciphertext and NOT plain text.
     */
    public function test_raw_database_column_values_are_ciphertext_and_never_plaintext(): void
    {
        $plainSecrets = [
            'client_secret' => 'super_sensitive_client_secret_9988',
            'access_token' => 'super_sensitive_access_token_1122',
            'refresh_token' => 'super_sensitive_refresh_token_3344',
            'webhook_secret' => 'super_sensitive_webhook_secret_5566',
        ];

        $integration = new EcommerceIntegration([
            'platform' => 'tiendanube',
            'name' => 'Tienda Nube DB Test',
            'store_id' => 'db_store_99',
            'client_id' => 'app_id_123',
        ]);

        foreach ($plainSecrets as $field => $secret) {
            $integration->setEncryptedSecret($field, $secret);
        }

        $integration->save();
        $this->assertNotNull($integration->id);

        // Fetch raw record directly using DB facade (bypassing Eloquent casts and models)
        $rawRecord = DB::table('ecommerce_integrations')->where('id', $integration->id)->first();
        $this->assertNotNull($rawRecord);

        foreach ($plainSecrets as $field => $secret) {
            $rawColumnValue = $rawRecord->{$field};

            // Assert column is populated
            $this->assertNotNull($rawColumnValue, "Raw column {$field} must not be null");

            // Assert raw column is NOT the plain text
            $this->assertNotEquals($secret, $rawColumnValue, "Raw column {$field} must not be plaintext");

            // Assert raw column does not even contain substring of plain text
            $this->assertStringNotContainsString($secret, $rawColumnValue, "Raw column {$field} must not leak plaintext substring");

            // Assert raw column decrypts via Crypt::decryptString to original plaintext
            $this->assertEquals(
                $secret,
                Crypt::decryptString($rawColumnValue),
                "Raw column {$field} must decrypt to the exact original plaintext"
            );
        }
    }

    /**
     * Requirement 3: setEncryptedSecret() does NOT overwrite an existing ciphertext
     * when the incoming value is masked with '****'.
     */
    public function test_set_encrypted_secret_does_not_overwrite_when_masked_with_asterisks(): void
    {
        $integration = new EcommerceIntegration([
            'platform' => 'woocommerce',
            'name' => 'Woo Mask Test',
            'base_url' => 'https://woo-store.example.com',
            'client_id' => 'ck_test_123',
        ]);

        $originalAccessToken = 'real_operational_access_token_ABC_XYZ_999';
        $integration->setEncryptedSecret('access_token', $originalAccessToken);
        $integration->save();

        $initialCiphertext = DB::table('ecommerce_integrations')->where('id', $integration->id)->value('access_token');
        $this->assertNotNull($initialCiphertext);

        // Adversarial mask patterns that could be submitted from UI/API forms
        $maskedInputs = [
            '****',
            'real****_999',
            '****_XYZ_999',
            'real_operational****',
            'APP_****a1b2',
            '************',
        ];

        foreach ($maskedInputs as $masked) {
            $integration->setEncryptedSecret('access_token', $masked);
            $integration->save();

            // Refresh raw value from database
            $persistedCiphertext = DB::table('ecommerce_integrations')->where('id', $integration->id)->value('access_token');

            $this->assertEquals(
                $initialCiphertext,
                $persistedCiphertext,
                "Mask pattern '{$masked}' should NOT have modified the stored ciphertext"
            );

            $this->assertEquals(
                $originalAccessToken,
                $integration->decrypted_access_token,
                "Decrypted access token should remain unchanged when mask '{$masked}' is passed"
            );
        }

        // Test that a legitimate new secret DOES overwrite the ciphertext
        $newLegitimateSecret = 'new_brand_fresh_token_555';
        $integration->setEncryptedSecret('access_token', $newLegitimateSecret);
        $integration->save();

        $updatedCiphertext = DB::table('ecommerce_integrations')->where('id', $integration->id)->value('access_token');
        $this->assertNotEquals($initialCiphertext, $updatedCiphertext);
        $this->assertEquals($newLegitimateSecret, $integration->decrypted_access_token);
        $this->assertEquals($newLegitimateSecret, Crypt::decryptString($updatedCiphertext));
    }

    /**
     * Requirement 4: Hidden attributes prevent secrets from being leaked
     * in $integration->toArray() or json_encode($integration).
     */
    public function test_hidden_attributes_prevent_secrets_leak_in_serialization(): void
    {
        $secrets = [
            'client_secret' => 'hidden_client_secret_111',
            'access_token' => 'hidden_access_token_222',
            'refresh_token' => 'hidden_refresh_token_333',
            'webhook_secret' => 'hidden_webhook_secret_444',
        ];

        $integration = new EcommerceIntegration([
            'platform' => 'ecommerce_platform',
            'name' => 'Internal Shop',
            'store_id' => 'internal_01',
        ]);

        foreach ($secrets as $field => $val) {
            $integration->setEncryptedSecret($field, $val);
        }

        $arrayRepresentation = $integration->toArray();
        $jsonRepresentation = json_encode($integration);

        foreach (array_keys($secrets) as $field) {
            // Must NOT exist as key in toArray()
            $this->assertArrayNotHasKey(
                $field,
                $arrayRepresentation,
                "Field {$field} must NOT appear in toArray() output"
            );
        }

        foreach ($secrets as $field => $plainVal) {
            // Neither plaintext nor ciphertext must appear in json_encode()
            $this->assertStringNotContainsString(
                $plainVal,
                $jsonRepresentation,
                "Plaintext of {$field} must not leak in json_encode()"
            );

            $ciphertext = $integration->getAttributes()[$field];
            $this->assertStringNotContainsString(
                $ciphertext,
                $jsonRepresentation,
                "Ciphertext of {$field} must not leak in json_encode()"
            );
        }

        // Also verify json_encode($arrayRepresentation) is leak-free
        $jsonFromArray = json_encode($arrayRepresentation);
        foreach ($secrets as $field => $plainVal) {
            $this->assertStringNotContainsString(
                $plainVal,
                $jsonFromArray,
                "Plaintext of {$field} must not leak in json_encode(toArray())"
            );
        }
    }

    /**
     * Edge case 1: Passing null or empty string to setEncryptedSecret clears the secret.
     */
    public function test_set_encrypted_secret_clears_field_when_null_or_empty_string(): void
    {
        $integration = new EcommerceIntegration;
        $integration->setEncryptedSecret('access_token', 'initial_token');
        $this->assertNotNull($integration->getAttributes()['access_token']);

        $integration->setEncryptedSecret('access_token', '');
        $this->assertNull($integration->getAttributes()['access_token']);
        $this->assertNull($integration->decrypted_access_token);

        $integration->setEncryptedSecret('access_token', 'second_token');
        $this->assertNotNull($integration->getAttributes()['access_token']);

        $integration->setEncryptedSecret('access_token', null);
        $this->assertNull($integration->getAttributes()['access_token']);
        $this->assertNull($integration->decrypted_access_token);
    }

    /**
     * Edge case 2: Tampered or corrupted ciphertext does not crash the system.
     */
    public function test_corrupted_ciphertext_gracefully_returns_null_without_crashing(): void
    {
        $integration = new EcommerceIntegration;
        // Inject invalid payload into raw attributes
        $attrs = $integration->getAttributes();
        $attrs['client_secret'] = 'corrupted-non-encrypted-payload-xyz';
        $integration->setRawAttributes($attrs);

        $this->assertNull(
            $integration->decrypted_client_secret,
            'Tampered or invalid ciphertext must gracefully return null instead of throwing an unhandled DecryptException'
        );
    }

    /**
     * Integration test: Adapters read the decrypted secrets seamlessly.
     */
    public function test_adapters_read_decrypted_secrets_seamlessly(): void
    {
        $integration = new EcommerceIntegration([
            'platform' => 'tiendanube',
            'name' => 'Store Test',
            'store_id' => 'tn_9988',
            'client_id' => 'tn_client_id',
        ]);

        $integration->setEncryptedSecret('access_token', 'tn_real_token');
        $integration->setEncryptedSecret('client_secret', 'tn_real_secret');
        $integration->setEncryptedSecret('refresh_token', 'tn_real_refresh');
        $integration->setEncryptedSecret('webhook_secret', 'tn_real_whsec');

        $adapter = $this->manager->forIntegration($integration);

        $this->assertEquals('tn_real_token', $adapter->getAccessToken());
        $this->assertEquals('tn_real_secret', $adapter->getClientSecret());
        $this->assertEquals('tn_real_refresh', $adapter->getRefreshToken());
        $this->assertEquals('tn_real_whsec', $adapter->getWebhookSecret());
        $this->assertEquals('tn_client_id', $adapter->getClientId());
        $this->assertEquals('tn_9988', $adapter->getStoreId());
    }

    /**
     * Adversarial Edge Case: Mass-assignment vs setEncryptedSecret behavior.
     * Note: With setClientSecretAttribute mutator in place, mass-assignment
     * automatically encrypts sensitive credentials.
     */
    public function test_adversarial_mass_assignment_direct_fill_behavior(): void
    {
        // When mass-assigned directly via constructor:
        $integration = new EcommerceIntegration([
            'client_secret' => 'plain_text_unprotected',
        ]);

        // Observation: Because setClientSecretAttribute mutator is in place,
        // it encrypts the raw attribute automatically:
        $this->assertNotEquals('plain_text_unprotected', $integration->getAttributes()['client_secret']);
        $this->assertEquals('plain_text_unprotected', $integration->decrypted_client_secret);

        // When setEncryptedSecret is used properly:
        $integration->setEncryptedSecret('client_secret', 'plain_text_protected');
        $this->assertNotEquals('plain_text_protected', $integration->getAttributes()['client_secret']);
        $this->assertEquals('plain_text_protected', $integration->decrypted_client_secret);
    }
}
