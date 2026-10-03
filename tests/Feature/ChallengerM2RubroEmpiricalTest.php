<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Rubro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ChallengerM2RubroEmpiricalTest
 *
 * Empirical verification and adversarial stress-testing for Milestone M2:
 * 1. Attempt to delete the system Rubro (must return 422).
 * 2. Attempt to delete a Rubro that has attached categories (must return 422).
 * 3. Verify creating a Rubro with duplicate name is rejected with 422.
 * 4. Verify updating and deleting an unattached non-system Rubro succeeds.
 * 5. Stress test tampering attacks (smuggling is_system, demoting system rubros).
 * 6. Stress test boundary conditions (255 vs 256 chars, whitespace, special characters).
 * 7. Stress test category reassignment lifecycle and cascade protection.
 */
class ChallengerM2RubroEmpiricalTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create([
            'role' => 'admin',
            'pin' => Hash::make('1234'),
        ]);

        $this->actingAsAdmin($this->adminUser);

        // Activar feature multi_rubro para pruebas CRUD
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'license_features_dict'],
            ['value' => json_encode(['multi_rubro' => true])]
        );
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'license_business_type'],
            ['value' => 'hardware_store']
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TASK 1.1: ATTEMPT TO DELETE THE SYSTEM RUBRO (MUST RETURN 422)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * EMPIRICAL CHECK: Deleting a system Rubro that has attached categories returns 422.
     */
    public function test_empirical_delete_system_rubro_with_categories_is_rejected_with_422(): void
    {
        $systemRubro = Rubro::create([
            'name' => 'Rubro Sistema Con Categorías',
            'is_system' => true,
        ]);

        Category::create([
            'name' => 'Herramientas Básicas',
            'rubro_id' => $systemRubro->id,
        ]);

        $response = $this->deleteJson("/api/catalog/rubros/{$systemRubro->id}");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'No se puede eliminar el rubro principal del sistema.');

        $this->assertDatabaseHas('rubros', [
            'id' => $systemRubro->id,
            'is_system' => 1,
        ]);
    }

    /**
     * EMPIRICAL CHECK: Even when the system Rubro has ZERO attached categories,
     * deletion MUST STILL be rejected with 422.
     */
    public function test_empirical_delete_system_rubro_without_categories_is_rejected_with_422(): void
    {
        $systemRubro = Rubro::create([
            'name' => 'Rubro Sistema Huérfano',
            'is_system' => true,
        ]);

        $this->assertEquals(0, $systemRubro->categories()->count());

        $response = $this->deleteJson("/api/catalog/rubros/{$systemRubro->id}");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'No se puede eliminar el rubro principal del sistema.');

        $this->assertDatabaseHas('rubros', [
            'id' => $systemRubro->id,
            'is_system' => 1,
        ]);
    }

    /**
     * ADVERSARIAL ATTACK: An attacker tries to demote a system rubro via PUT (is_system: false)
     * to subsequently delete it. The controller must ignore/unset is_system and block deletion.
     */
    public function test_adversarial_tamper_attempt_to_demote_system_rubro_is_neutralized(): void
    {
        $systemRubro = Rubro::create([
            'name' => 'Rubro Blindado',
            'is_system' => true,
        ]);

        // Intentar cambiar is_system a false mediante PUT
        $updateRes = $this->putJson("/api/catalog/rubros/{$systemRubro->id}", [
            'name' => 'Rubro Blindado Renombrado',
            'is_system' => false,
        ]);

        $updateRes->assertStatus(200);

        // En la base de datos, is_system DEBE seguir siendo true (1)
        $systemRubro->refresh();
        $this->assertTrue((bool) $systemRubro->is_system, 'Vulnerabilidad: is_system fue alterado a false por un request PUT');

        // Intento subsiguiente de borrar debe fallar con 422
        $deleteRes = $this->deleteJson("/api/catalog/rubros/{$systemRubro->id}");
        $deleteRes->assertStatus(422)
            ->assertJsonPath('message', 'No se puede eliminar el rubro principal del sistema.');

        $this->assertDatabaseHas('rubros', ['id' => $systemRubro->id]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TASK 1.2: ATTEMPT TO DELETE A RUBRO WITH ATTACHED CATEGORIES (MUST RETURN 422)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * EMPIRICAL CHECK: Deleting a non-system Rubro with 1 attached category returns 422.
     */
    public function test_empirical_delete_rubro_with_single_attached_category_is_rejected_with_422(): void
    {
        $rubro = Rubro::create([
            'name' => 'Pinturas y Accesorios',
            'is_system' => false,
        ]);

        $category = Category::create([
            'name' => 'Esmaltes Al agua',
            'rubro_id' => $rubro->id,
        ]);

        $response = $this->deleteJson("/api/catalog/rubros/{$rubro->id}");

        $response->assertStatus(422)
            ->assertJsonPath('message', fn ($msg) => str_contains($msg, 'tiene 1 categoría(s) asociada(s)'));

        $this->assertDatabaseHas('rubros', ['id' => $rubro->id]);
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'rubro_id' => $rubro->id]);
    }

    /**
     * EMPIRICAL CHECK: Deleting a non-system Rubro with multiple (3) attached categories returns 422.
     */
    public function test_empirical_delete_rubro_with_multiple_attached_categories_is_rejected_with_422(): void
    {
        $rubro = Rubro::create([
            'name' => 'Construcción en Seco',
            'is_system' => false,
        ]);

        Category::create(['name' => 'Perfilería Galvanizada', 'rubro_id' => $rubro->id]);
        Category::create(['name' => 'Placas de Yeso 12.5mm', 'rubro_id' => $rubro->id]);
        Category::create(['name' => 'Tornillos T2 y T3', 'rubro_id' => $rubro->id]);

        $response = $this->deleteJson("/api/catalog/rubros/{$rubro->id}");

        $response->assertStatus(422)
            ->assertJsonPath('message', fn ($msg) => str_contains($msg, 'tiene 3 categoría(s) asociada(s)'));

        $this->assertDatabaseHas('rubros', ['id' => $rubro->id]);
    }

    /**
     * EMPIRICAL CHECK: Database referential integrity - Deleting an attached rubro via API
     * NEVER triggers accidental cascade delete on categories.
     */
    public function test_empirical_categories_are_not_cascade_wiped_by_blocked_rubro_deletion(): void
    {
        $rubro = Rubro::create(['name' => 'Seguridad Vial', 'is_system' => false]);
        $cat1 = Category::create(['name' => 'Conos Reflectivos', 'rubro_id' => $rubro->id]);
        $cat2 = Category::create(['name' => 'Balizas', 'rubro_id' => $rubro->id]);

        $response = $this->deleteJson("/api/catalog/rubros/{$rubro->id}");
        $response->assertStatus(422);

        // Both categories must remain intact
        $this->assertDatabaseHas('categories', ['id' => $cat1->id]);
        $this->assertDatabaseHas('categories', ['id' => $cat2->id]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TASK 1.3: VERIFY CREATING A RUBRO WITH DUPLICATE NAME IS REJECTED WITH 422
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * EMPIRICAL CHECK: Creating a Rubro with duplicate name is rejected with 422 validation error.
     */
    public function test_empirical_duplicate_name_on_create_is_rejected_with_422(): void
    {
        Rubro::create([
            'name' => 'Ferretería General',
            'is_system' => false,
        ]);

        $response = $this->postJson('/api/catalog/rubros', [
            'name' => 'Ferretería General',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * EMPIRICAL CHECK: Leading or trailing whitespace is trimmed and duplicate name collision is detected.
     */
    public function test_empirical_duplicate_name_with_surrounding_whitespace_is_rejected_with_422(): void
    {
        Rubro::create([
            'name' => 'Sanitarios y Griferías',
            'is_system' => false,
        ]);

        $response = $this->postJson('/api/catalog/rubros', [
            'name' => '   Sanitarios y Griferías   ',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * EMPIRICAL CHECK: Updating a Rubro with the name of another existing Rubro is rejected with 422.
     */
    public function test_empirical_duplicate_name_on_update_is_rejected_with_422(): void
    {
        $rubroA = Rubro::create(['name' => 'Bazar Gastronómico', 'is_system' => false]);
        $rubroB = Rubro::create(['name' => 'Electrodomésticos', 'is_system' => false]);

        $response = $this->putJson("/api/catalog/rubros/{$rubroB->id}", [
            'name' => 'Bazar Gastronómico',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * EMPIRICAL CHECK: Updating a Rubro retaining its OWN current name must succeed with 200 (ignore self).
     */
    public function test_empirical_update_rubro_with_own_existing_name_succeeds_with_200(): void
    {
        $rubro = Rubro::create(['name' => 'Maderas y Placas', 'is_system' => false]);

        $response = $this->putJson("/api/catalog/rubros/{$rubro->id}", [
            'name' => 'Maderas y Placas',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('name', 'Maderas y Placas');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TASK 1.4: VERIFY UPDATING AND DELETING AN UNATTACHED NON-SYSTEM RUBRO SUCCEEDS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * EMPIRICAL CHECK: Updating an unattached non-system Rubro succeeds with 200 and persists.
     */
    public function test_empirical_update_unattached_non_system_rubro_succeeds_with_200(): void
    {
        $rubro = Rubro::create([
            'name' => 'Jardinería Antigua',
            'is_system' => false,
        ]);

        $response = $this->putJson("/api/catalog/rubros/{$rubro->id}", [
            'name' => 'Jardinería y Paisajismo Moderno',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('id', $rubro->id)
            ->assertJsonPath('name', 'Jardinería y Paisajismo Moderno')
            ->assertJsonPath('is_system', false);

        $this->assertDatabaseHas('rubros', [
            'id' => $rubro->id,
            'name' => 'Jardinería y Paisajismo Moderno',
            'is_system' => false,
        ]);
    }

    /**
     * EMPIRICAL CHECK: Deleting an unattached non-system Rubro succeeds with 200 and removes row.
     */
    public function test_empirical_delete_unattached_non_system_rubro_succeeds_with_200(): void
    {
        $rubro = Rubro::create([
            'name' => 'Rubro Huérfano Descartable',
            'is_system' => false,
        ]);

        $response = $this->deleteJson("/api/catalog/rubros/{$rubro->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', fn ($msg) => str_contains($msg, 'eliminado correctamente'));

        $this->assertDatabaseMissing('rubros', [
            'id' => $rubro->id,
        ]);
    }

    /**
     * EMPIRICAL CHECK: Reassigning attached categories away from a non-system Rubro releases
     * the deletion guardrail, allowing successful deletion.
     */
    public function test_empirical_reassigning_categories_releases_deletion_guardrail(): void
    {
        $rubroOld = Rubro::create(['name' => 'Rubro Origen A', 'is_system' => false]);
        $rubroNew = Rubro::create(['name' => 'Rubro Destino B', 'is_system' => false]);

        $category = Category::create([
            'name' => 'Categoría Móvil',
            'rubro_id' => $rubroOld->id,
        ]);

        // Intentar borrar rubroOld -> Bloqueado con 422
        $this->deleteJson("/api/catalog/rubros/{$rubroOld->id}")->assertStatus(422);

        // Reasignar categoría a rubroNew
        $updateCatRes = $this->putJson("/api/catalog/categories/{$category->id}", [
            'name' => 'Categoría Móvil',
            'rubro_id' => $rubroNew->id,
        ]);
        $updateCatRes->assertStatus(200);

        // Ahora rubroOld no tiene categorías -> Eliminación DEBE ser 200
        $deleteOldRes = $this->deleteJson("/api/catalog/rubros/{$rubroOld->id}");
        $deleteOldRes->assertStatus(200);
        $this->assertDatabaseMissing('rubros', ['id' => $rubroOld->id]);

        // rubroNew ahora tiene la categoría -> Su eliminación DEBE ser 422
        $deleteNewRes = $this->deleteJson("/api/catalog/rubros/{$rubroNew->id}");
        $deleteNewRes->assertStatus(422);
        $this->assertDatabaseHas('rubros', ['id' => $rubroNew->id]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ADVERSARIAL HARDENING & EDGE CASE BOUNDARIES
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * ADVERSARIAL ATTACK: Attempting to create a system Rubro via POST payload.
     * The controller must override/force is_system = false.
     */
    public function test_adversarial_create_rubro_cannot_smuggle_is_system_true(): void
    {
        $response = $this->postJson('/api/catalog/rubros', [
            'name' => 'Rubro Falso Sistema',
            'is_system' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Rubro Falso Sistema')
            ->assertJsonPath('is_system', false);

        $rubroId = $response->json('id');
        $this->assertDatabaseHas('rubros', [
            'id' => $rubroId,
            'is_system' => 0,
        ]);
    }

    /**
     * ADVERSARIAL BOUNDARY: String length boundaries: 255 chars succeeds, 256 chars fails 422.
     */
    public function test_adversarial_string_length_boundaries_255_vs_256(): void
    {
        $name255 = str_repeat('X', 255);
        $name256 = str_repeat('Y', 256);

        // 255 chars -> 201
        $res255 = $this->postJson('/api/catalog/rubros', ['name' => $name255]);
        $res255->assertStatus(201);
        $this->assertDatabaseHas('rubros', ['name' => $name255]);

        // 256 chars -> 422
        $res256 = $this->postJson('/api/catalog/rubros', ['name' => $name256]);
        $res256->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * ADVERSARIAL INJECTION & UTF-8 RESILIENCE:
     * Special characters, emojis, and potential SQL injection sequences are safely handled.
     */
    public function test_adversarial_special_characters_emojis_and_sql_injection_strings(): void
    {
        $specialNames = [
            'Ferretería & Cerrajería 24/7',
            'Herramientas 🔧 y Pinturas 🎨',
            "' OR '1'='1' --",
            '<script>alert("xss")</script>',
            'Cafetería / Bar & Delicatessen',
            'N° 1 en Plomería & Gas (Tigre)',
        ];

        foreach ($specialNames as $specialName) {
            $response = $this->postJson('/api/catalog/rubros', [
                'name' => $specialName,
            ]);

            $response->assertStatus(201)
                ->assertJsonPath('name', $specialName);

            $this->assertDatabaseHas('rubros', [
                'name' => $specialName,
            ]);
        }
    }

    /**
     * ADVERSARIAL VALIDATION: Missing, empty, or non-string names are rejected with 422.
     */
    public function test_adversarial_missing_and_invalid_data_types_rejected_with_422(): void
    {
        // 1. Missing name
        $this->postJson('/api/catalog/rubros', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        // 2. Empty string
        $this->postJson('/api/catalog/rubros', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        // 3. Array name
        $this->postJson('/api/catalog/rubros', ['name' => ['invalid', 'array']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        // 4. Boolean name
        $this->postJson('/api/catalog/rubros', ['name' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * ADVERSARIAL NON-EXISTENT ENTITY: Non-existent ID returns 404 ModelNotFound.
     */
    public function test_adversarial_non_existent_rubro_returns_404(): void
    {
        $this->getJson('/api/catalog/rubros/999999')->assertStatus(404);
        $this->putJson('/api/catalog/rubros/999999', ['name' => 'Inexistente'])->assertStatus(404);
        $this->deleteJson('/api/catalog/rubros/999999')->assertStatus(404);
    }
}
