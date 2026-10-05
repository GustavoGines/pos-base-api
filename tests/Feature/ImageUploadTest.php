<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }


    public function test_it_can_upload_business_logo()
    {
        $file = UploadedFile::fake()->image('logo.png');

        $response = $this->actingAsAdmin()->postJson('/api/settings/logo', [
            'logo' => $file,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['message', 'logo_path']);

        $path = BusinessSetting::where('key', 'logo_path')->value('value');
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_it_can_upload_and_delete_product_image()
    {
        $product = Product::create([
            'name' => 'Test Product',
            'internal_code' => 'IMG-001',
            'cost_price' => 10,
            'selling_price' => 20,
            'stock' => 5
        ]);
        $file = UploadedFile::fake()->image('product.jpg');

        // Upload
        $response = $this->actingAsAdmin()->postJson("/api/catalog/products/{$product->id}/image", [
            'image' => $file,
        ]);

        $response->assertStatus(200);
        
        $product->refresh();
        $this->assertNotNull($product->image_path);
        Storage::disk('public')->assertExists($product->image_path);

        // Delete
        $deleteResponse = $this->actingAsAdmin()->deleteJson("/api/catalog/products/{$product->id}/image");
        $deleteResponse->assertStatus(204);

        Storage::disk('public')->assertMissing($product->image_path);
        $product->refresh();
        $this->assertNull($product->image_path);
    }
}
