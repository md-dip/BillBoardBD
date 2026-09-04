<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cover for the shared /api/* catch-all (routes/api/shared.php ->
 * Shared\NotFoundController): an unknown endpoint must answer 404 on the same
 * success/data/message envelope as everything else, and must not shadow any
 * real route.
 */
class NotFoundTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_api_url_returns_the_404_envelope(): void
    {
        $this->getJson('/api/this-endpoint-does-not-exist')
            ->assertStatus(404)
            ->assertExactJson([
                'success' => false,
                'data' => null,
                'message' => 'Not found.',
            ]);
    }

    public function test_unknown_nested_api_url_returns_404(): void
    {
        $this->getJson('/api/admin/nope/deeper')->assertStatus(404);
        $this->getJson('/api/owner/nope')->assertStatus(404);
    }

    public function test_unknown_api_url_returns_404_for_a_write_verb(): void
    {
        $this->postJson('/api/nope')->assertStatus(404);
        $this->patchJson('/api/nope')->assertStatus(404);
        $this->deleteJson('/api/nope')->assertStatus(404);
    }

    public function test_the_catch_all_does_not_shadow_a_real_route(): void
    {
        $this->getJson('/api/settings/public')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }
}
