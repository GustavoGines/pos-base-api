<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Category;
use App\Models\Rubro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Empirical Adversarial Test Suite for Milestone M3 (Basic vs Premium Gating & Rubro/Category Hierarchy)
 *
 * Verifies all security invariants, feature gates, forged payloads, and fallback behaviors.
 */
class RubroEmpiricalAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Rubro $systemRubro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAsAdmin($this->admin);

        $this->systemRubro = Rubro::where('is_system', true)->first()
            ?? Rubro::create(['name' => 'Ferretería General', 'is_system' => true]);
    }

    protected function setLicenseFeatures(?array $features): void
    {
        if ($features === null) {
            DB::table('business_settings')->where('key', 'license_features_dict')->delete();
        } else {
            BusinessSetting::updateOrCreate(
                ['key' => 'license_features_dict'],
                ['value' => json_encode($features)]
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TASK 1A: Basic Plan Tenant Mutations Blocked with HTTP 403 FEATURE_NOT_LICENSED
    // ─────────────────────────────────────────────────────────────────────────

    public function test_basic_plan_cannot_post_rubros_under_any_payload(): void
    {
        $this->setLicenseFeatures(['multi_rubro' => false]);

        // Attempt 1: Normal payload
        $res1 = $this->postJson('/api/catalog/rubros', ['name' => 'Adversarial Rubro 1']);
        $res1->assertStatus(403)
            ->assertJsonPath('error_code', 'FEATURE_NOT_LICENSED')
            ->assertJsonPath('required', 'multi_rubro');

        // Attempt 2: Forged is_system payload
        $res2 = $this->postJson('/api/catalog/rubros', [
            'name' => 'Adversarial Rubro 2',
            'is_system' => true,
        ]);
        $res2->assertStatus(403)
            ->assertJsonPath('error_code', 'FEATURE_NOT_LICENSED')
            ->assertJsonPath('required', 'multi_rubro');

        // Attempt 3: Empty payload
        $res3 = $this->postJson('/api/catalog/rubros', []);
        $res3->assertStatus(403)
            ->assertJsonPath('error_code', 'FEATURE_NOT_LICENSED')
            ->assertJsonPath('required', 'multi_rubro');

        $this->assertDatabaseMissing('rubros', ['name' => 'Adversarial Rubro 1']);
        $this->assertDatabaseMissing('rubros', ['name' => 'Adversarial Rubro 2']);
    }

    public function test_basic_plan_cannot_put_rubros(): void
    {
        $this->setLicenseFeatures(['multi_rubro' => false]);

        // Attempt on system rubro -> 403 FEATURE_NOT_LICENSED
        $res1 = $this->putJson("/api/catalog/rubros/{$this->systemRubro->id}", [
            'name' => 'Hacked System Name',
        ]);
        $res1->assertStatus(403)
            ->assertJsonPath('error_code', 'FEATURE_NOT_LICENSED')
            ->assertJsonPath('required', 'multi_rubro');

        // Attempt on another rubro pre-existing in DB -> 403 FEATURE_NOT_LICENSED
        $custom = Rubro::create(['name' => 'Preexisting Rubro', 'is_system' => false]);
        $res2 = $this->putJson("/api/catalog/rubros/{$custom->id}", [
            'name' => 'Hacked Custom Name',
        ]);
        $res2->assertStatus(403)
            ->assertJsonPath('error_code', 'FEATURE_NOT_LICENSED')
            ->assertJsonPath('required', 'multi_rubro');

        // Attempt on non-existent rubro -> 404 (Route Model Binding)
        $res3 = $this->putJson('/api/catalog/rubros/999999', [
            'name' => 'Non Existent',
        ]);
        $res3->assertStatus(404);

        $this->assertDatabaseHas('rubros', [
            'id' => $this->systemRubro->id,
            'name' => $this->systemRubro->name,
        ]);
        $this->assertDatabaseHas('rubros', [
            'id' => $custom->id,
            'name' => 'Preexisting Rubro',
        ]);
    }

    public function test_basic_plan_cannot_delete_rubros(): void
    {
        $this->setLicenseFeatures(['multi_rubro' => false]);

        // Attempt on system rubro -> 403 FEATURE_NOT_LICENSED
        $res1 = $this->deleteJson("/api/catalog/rubros/{$this->systemRubro->id}");
        $res1->assertStatus(403)
            ->assertJsonPath('error_code', 'FEATURE_NOT_LICENSED')
            ->assertJsonPath('required', 'multi_rubro');

        // Attempt on pre-existing custom rubro -> 403 FEATURE_NOT_LICENSED
        $custom = Rubro::create(['name' => 'Preexisting Rubro To Delete', 'is_system' => false]);
        $res2 = $this->deleteJson("/api/catalog/rubros/{$custom->id}");
        $res2->assertStatus(403)
            ->assertJsonPath('error_code', 'FEATURE_NOT_LICENSED')
            ->assertJsonPath('required', 'multi_rubro');

        // Attempt on non-existent rubro -> 404 (Route Model Binding)
        $res3 = $this->deleteJson('/api/catalog/rubros/999999');
        $res3->assertStatus(404);

        $this->assertDatabaseHas('rubros', ['id' => $this->systemRubro->id]);
        $this->assertDatabaseHas('rubros', ['id' => $custom->id]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TASK 1B: Basic Plan Category Creation with Forged rubro_id is Forced to System Rubro
    // ─────────────────────────────────────────────────────────────────────────

    public function test_basic_plan_post_category_with_forged_rubro_id_forces_system_default(): void
    {
        $this->setLicenseFeatures(['multi_rubro' => false]);

        $customRubro = Rubro::create(['name' => 'Existing Non-System Rubro', 'is_system' => false]);

        $forgedValues = [
            'forged_valid_other_rubro' => $customRubro->id,
            'forged_non_existent' => 888888,
            'forged_negative' => -5,
            'forged_zero' => 0,
            'forged_null' => null,
        ];

        foreach ($forgedValues as $scenario => $forgedId) {
            $payload = [
                'name' => "Cat_{$scenario}_" . uniqid(),
                'rubro_id' => $forgedId,
            ];

            $res = $this->postJson('/api/catalog/categories', $payload);

            $res->assertStatus(201)
                ->assertJsonPath('rubro_id', $this->systemRubro->id)
                ->assertJsonPath('rubro.id', $this->systemRubro->id)
                ->assertJsonPath('rubro.is_system', true);

            $this->assertDatabaseHas('categories', [
                'name' => $payload['name'],
                'rubro_id' => $this->systemRubro->id,
            ]);
        }
    }

    public function test_basic_plan_put_category_with_forged_rubro_id_does_not_mutate_rubro(): void
    {
        $this->setLicenseFeatures(['multi_rubro' => false]);

        $customRubro = Rubro::create(['name' => 'Another Rubro', 'is_system' => false]);
        $category = Category::create([
            'name' => 'Immutable Category',
            'rubro_id' => $this->systemRubro->id,
        ]);

        // Attempt update with forged rubro_id
        $res = $this->putJson("/api/catalog/categories/{$category->id}", [
            'name' => 'Renamed Immutable Category',
            'rubro_id' => $customRubro->id,
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('name', 'Renamed Immutable Category')
            ->assertJsonPath('rubro_id', $this->systemRubro->id);

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'Renamed Immutable Category',
            'rubro_id' => $this->systemRubro->id,
        ]);
    }

    public function test_basic_plan_put_category_auto_repairs_legacy_null_rubro_id(): void
    {
        $this->setLicenseFeatures(['multi_rubro' => false]);

        // Legacy category without rubro_id
        $legacyCategory = Category::create([
            'name' => 'Legacy Orphan Category',
            'rubro_id' => null,
        ]);

        $res = $this->putJson("/api/catalog/categories/{$legacyCategory->id}", [
            'name' => 'Repaired Category',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('rubro_id', $this->systemRubro->id);

        $this->assertDatabaseHas('categories', [
            'id' => $legacyCategory->id,
            'rubro_id' => $this->systemRubro->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TASK 1C: Missing or False Features Dict Strictly Defaults to Basic Behavior
    // ─────────────────────────────────────────────────────────────────────────

    public function test_missing_license_features_dict_treated_as_basic_plan(): void
    {
        $this->setLicenseFeatures(null);

        // POST rubro blocked
        $this->postJson('/api/catalog/rubros', ['name' => 'Should Fail'])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FEATURE_NOT_LICENSED');

        // POST category forces default rubro
        $res = $this->postJson('/api/catalog/categories', [
            'name' => 'Cat Missing Dict',
            'rubro_id' => 999999,
        ]);
        $res->assertStatus(201)
            ->assertJsonPath('rubro_id', $this->systemRubro->id);
    }

    public function test_non_boolean_features_dict_values_treated_as_basic_plan(): void
    {
        // multi_rubro => 0 (int)
        $this->setLicenseFeatures(['multi_rubro' => 0]);
        $this->postJson('/api/catalog/rubros', ['name' => 'Int Zero'])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FEATURE_NOT_LICENSED');

        // multi_rubro => "true" (string, not strict bool)
        $this->setLicenseFeatures(['multi_rubro' => 'true']);
        $this->postJson('/api/catalog/rubros', ['name' => 'String True'])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FEATURE_NOT_LICENSED');

        // multi_rubro => null
        $this->setLicenseFeatures(['multi_rubro' => null]);
        $this->postJson('/api/catalog/rubros', ['name' => 'Null Value'])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FEATURE_NOT_LICENSED');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TASK 1D: Premium Plan Tenant Full Rubro CRUD and Category Assignment
    // ─────────────────────────────────────────────────────────────────────────

    public function test_premium_plan_full_rubro_crud_lifecycle_and_category_assignment(): void
    {
        $this->setLicenseFeatures(['multi_rubro' => true]);

        // 1. CREATE Rubro
        $resCreate = $this->postJson('/api/catalog/rubros', [
            'name' => 'Electricidad y Automatización',
        ]);
        $resCreate->assertStatus(201)
            ->assertJsonPath('name', 'Electricidad y Automatización')
            ->assertJsonPath('is_system', false);
        $rubroId = $resCreate->json('id');
        $this->assertNotNull($rubroId);

        // 2. LIST Rubros
        $resList = $this->getJson('/api/catalog/rubros');
        $resList->assertStatus(200)
            ->assertJsonFragment(['name' => 'Electricidad y Automatización'])
            ->assertJsonFragment(['name' => $this->systemRubro->name]);

        // 3. SHOW Rubro
        $resShow = $this->getJson("/api/catalog/rubros/{$rubroId}");
        $resShow->assertStatus(200)
            ->assertJsonPath('id', $rubroId)
            ->assertJsonPath('name', 'Electricidad y Automatización');

        // 4. UPDATE Rubro
        $resUpdate = $this->putJson("/api/catalog/rubros/{$rubroId}", [
            'name' => 'Electricidad Industrial y Doméstica',
        ]);
        $resUpdate->assertStatus(200)
            ->assertJsonPath('name', 'Electricidad Industrial y Doméstica');
        $this->assertDatabaseHas('rubros', [
            'id' => $rubroId,
            'name' => 'Electricidad Industrial y Doméstica',
        ]);

        // 5. CREATE Category with valid custom rubro_id
        $resCat = $this->postJson('/api/catalog/categories', [
            'name' => 'Cables y Conductores',
            'rubro_id' => $rubroId,
        ]);
        $resCat->assertStatus(201)
            ->assertJsonPath('name', 'Cables y Conductores')
            ->assertJsonPath('rubro_id', $rubroId)
            ->assertJsonPath('rubro.name', 'Electricidad Industrial y Doméstica');
        $catId = $resCat->json('id');

        // 6. DELETE Rubro BLOCKED because category is assigned
        $resDelFail = $this->deleteJson("/api/catalog/rubros/{$rubroId}");
        $resDelFail->assertStatus(422);
        $this->assertDatabaseHas('rubros', ['id' => $rubroId]);

        // 7. REASSIGN Category to system rubro
        $resReassign = $this->putJson("/api/catalog/categories/{$catId}", [
            'rubro_id' => $this->systemRubro->id,
        ]);
        $resReassign->assertStatus(200)
            ->assertJsonPath('rubro_id', $this->systemRubro->id);

        // 8. DELETE Rubro SUCCEEDS after category reassignment
        $resDelSuccess = $this->deleteJson("/api/catalog/rubros/{$rubroId}");
        $resDelSuccess->assertStatus(200);
        $this->assertDatabaseMissing('rubros', ['id' => $rubroId]);
    }

    public function test_premium_plan_validates_rubro_id_and_rejects_non_existent(): void
    {
        $this->setLicenseFeatures(['multi_rubro' => true]);

        // Create with fake rubro_id -> 422
        $res = $this->postJson('/api/catalog/categories', [
            'name' => 'Invalid Cat',
            'rubro_id' => 999999,
        ]);
        $res->assertStatus(422)
            ->assertJsonValidationErrors(['rubro_id']);

        // Update with fake rubro_id -> 422
        $category = Category::create([
            'name' => 'Existing Cat',
            'rubro_id' => $this->systemRubro->id,
        ]);
        $this->putJson("/api/catalog/categories/{$category->id}", [
            'rubro_id' => 999999,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['rubro_id']);
    }

    public function test_premium_plan_cannot_elevate_is_system_flag(): void
    {
        $this->setLicenseFeatures(['multi_rubro' => true]);

        // Attempting to create a custom rubro with is_system = true
        $resCreate = $this->postJson('/api/catalog/rubros', [
            'name' => 'Attempted System Rubro',
            'is_system' => true,
        ]);
        $resCreate->assertStatus(201)
            ->assertJsonPath('is_system', false);

        $rubroId = $resCreate->json('id');
        $this->assertDatabaseHas('rubros', [
            'id' => $rubroId,
            'is_system' => false,
        ]);

        // Attempting to update rubro to is_system = true
        $resUpdate = $this->putJson("/api/catalog/rubros/{$rubroId}", [
            'name' => 'Attempted System Rubro Update',
            'is_system' => true,
        ]);
        $resUpdate->assertStatus(200);

        $this->assertDatabaseHas('rubros', [
            'id' => $rubroId,
            'is_system' => false,
        ]);
    }
}
