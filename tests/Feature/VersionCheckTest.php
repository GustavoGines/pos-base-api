<?php

namespace Tests\Feature;

use Tests\TestCase;

class VersionCheckTest extends TestCase
{
    public function test_version_check_returns_version_and_vendor_hash(): void
    {
        $response = $this->getJson('/api/version-check');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'version',
                'vendor_hash',
            ]);

        $data = $response->json();
        $this->assertNotEmpty($data['version']);

        if (file_exists(base_path('composer.lock'))) {
            $this->assertSame(
                hash_file('sha256', base_path('composer.lock')),
                $data['vendor_hash']
            );
        }
    }
}
