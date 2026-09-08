<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Three actors signed in at once, from one browser.
 *
 * The frontend keeps each tab's token in sessionStorage rather than
 * localStorage (see frontend/src/shared/api/tokenStore.js), so the client,
 * owner and admin views can be open in three tabs of the same Chrome profile
 * without overwriting one another. That only works if the API is happy to hand
 * out and honour several live tokens at the same time, which is what this
 * covers - login mints a token rather than replacing the previous one, and
 * logout revokes exactly the token it was called with.
 */
class ConcurrentActorSessionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => Hash::make('password'), 'role' => 'admin']);
        User::create(['name' => 'Owner', 'email' => 'owner@test.com', 'password' => Hash::make('password'), 'role' => 'owner']);
        User::create(['name' => 'Client', 'email' => 'client@test.com', 'password' => Hash::make('password'), 'role' => 'client']);
    }

    /** Sign in and return the API token, the way a tab's login form does. */
    private function tokenFor(string $email): string
    {
        return $this->postJson('/api/login', ['email' => $email, 'password' => 'password'])
            ->assertOk()
            ->json('data.token');
    }

    /**
     * Act as one browser tab holding $token.
     *
     * forgetGuards() is what makes several tokens usable inside a single test.
     * Sanctum's RequestGuard caches the user it resolved on the first call, and
     * every later call in the same test would keep answering as that user no
     * matter which Bearer token is sent - an artefact of reusing one booted
     * app, not something a real browser can hit. Dropping the resolved guard
     * forces the token header to be read again, which is what three separate
     * tabs really do.
     */
    private function asTab(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_three_actors_hold_live_tokens_at_the_same_time(): void
    {
        $admin = $this->tokenFor('admin@test.com');
        $owner = $this->tokenFor('owner@test.com');
        $client = $this->tokenFor('client@test.com');

        // Signing in as the next actor must not have invalidated the previous
        // one - that is the whole point of a per-tab session.
        $this->assertCount(3, array_unique([$admin, $owner, $client]), 'each login should mint its own token');

        $this->asTab($admin)->getJson('/api/me')->assertOk()->assertJsonPath('data.role', 'admin');
        $this->asTab($owner)->getJson('/api/me')->assertOk()->assertJsonPath('data.role', 'owner');
        $this->asTab($client)->getJson('/api/me')->assertOk()->assertJsonPath('data.role', 'client');
    }

    public function test_each_token_only_reaches_its_own_actors_routes(): void
    {
        $admin = $this->tokenFor('admin@test.com');
        $client = $this->tokenFor('client@test.com');

        $this->asTab($admin)->getJson('/api/admin/ping')->assertOk();

        // The client tab stays a client even while an admin tab is open.
        $this->asTab($client)->getJson('/api/admin/ping')->assertForbidden();
    }

    public function test_logging_one_tab_out_leaves_the_others_signed_in(): void
    {
        $admin = $this->tokenFor('admin@test.com');
        $owner = $this->tokenFor('owner@test.com');
        $client = $this->tokenFor('client@test.com');

        $this->asTab($owner)->postJson('/api/logout')->assertOk();

        $this->asTab($owner)->getJson('/api/me')->assertUnauthorized();
        $this->asTab($admin)->getJson('/api/me')->assertOk()->assertJsonPath('data.role', 'admin');
        $this->asTab($client)->getJson('/api/me')->assertOk()->assertJsonPath('data.role', 'client');
    }

    public function test_the_same_actor_can_be_open_in_two_tabs_independently(): void
    {
        $first = $this->tokenFor('admin@test.com');
        $second = $this->tokenFor('admin@test.com');

        $this->assertNotSame($first, $second, 'a second tab must get its own token, not a copy');

        $this->asTab($first)->postJson('/api/logout')->assertOk();

        // Closing one admin tab must not sign the other one out.
        $this->asTab($second)->getJson('/api/me')->assertOk()->assertJsonPath('data.role', 'admin');
    }
}
