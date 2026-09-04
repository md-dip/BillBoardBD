<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Cover for the api/* AuthenticationException handler in bootstrap/app.php.
 * A signed-out visitor hitting a guarded endpoint (the booking card's
 * "Hold these dates" is the one users actually meet) must read plain language,
 * not Laravel's "Unauthenticated.".
 */
class UnauthenticatedTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guarded_endpoint_answers_in_plain_language(): void
    {
        $this->postJson('/api/bookings/hold', [
            'billboard_id' => 1,
            'start_date' => '2026-09-10',
            'end_date' => '2026-09-11',
        ])
            ->assertStatus(401)
            ->assertExactJson([
                'success' => false,
                'data' => null,
                'message' => 'Please log in or register first.',
            ]);
    }

    public function test_a_dead_token_gets_the_same_answer(): void
    {
        $this->withHeader('Authorization', 'Bearer not-a-real-token')
            ->getJson('/api/me')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Please log in or register first.');
    }

    public function test_it_does_not_swallow_the_invalid_credentials_message(): void
    {
        User::create(['name' => 'Real', 'email' => 'real@test.com', 'password' => Hash::make('password'), 'role' => 'client']);

        // Same 401 status, but this one is the login controller's own answer -
        // the handler must leave it alone or the login form stops making sense.
        $this->postJson('/api/login', ['email' => 'real@test.com', 'password' => 'wrong-password'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Invalid credentials');
    }
}
