<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Cover for the forgot/reset password pair in Shared/AuthController.
 *
 * Most of the machinery is Laravel's password broker, so what is worth pinning
 * here is the part this app decided for itself: the emailed link must point at
 * the React SPA (AppServiceProvider's createUrlUsing), an unknown address must
 * be indistinguishable from a known one, and a completed reset must revoke the
 * Sanctum tokens the old password had issued.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_emailed_link_points_at_the_react_app(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'reset-me@example.com']);

        $this->postJson('/api/forgot-password', ['email' => 'reset-me@example.com'])
            ->assertOk()
            ->assertJsonPath('success', true);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            // The default notification would build route('password.reset'),
            // which does not exist in this API-only app.
            $this->assertStringContainsString('/reset-password?token=', $url);
            $this->assertStringContainsString('reset-me%40example.com', $url);

            return true;
        });
    }

    public function test_an_unknown_email_is_answered_exactly_like_a_known_one(): void
    {
        Notification::fake();

        $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'If that email has an account, a reset link is on its way.');

        Notification::assertNothingSent();
    }

    public function test_a_reset_replaces_the_password_and_revokes_existing_tokens(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'reset-me@example.com',
            'password' => 'oldpassword',
        ]);

        // A token issued under the old password must not outlive it.
        $user->createToken('api-token');
        $this->assertSame(1, $user->tokens()->count());

        $this->postJson('/api/forgot-password', ['email' => 'reset-me@example.com'])->assertOk();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'reset-me@example.com',
            'password' => 'newpassword',
            'password_confirmation' => 'newpassword',
        ])->assertOk()->assertJsonPath('success', true);

        $user->refresh();
        $this->assertTrue(Hash::check('newpassword', $user->password));
        $this->assertSame(0, $user->tokens()->count(), 'the old password\'s API tokens should be gone');

        $this->postJson('/api/login', ['email' => 'reset-me@example.com', 'password' => 'oldpassword'])
            ->assertStatus(401);

        $this->postJson('/api/login', ['email' => 'reset-me@example.com', 'password' => 'newpassword'])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_a_reset_token_cannot_be_used_twice(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'reset-me@example.com']);
        $this->postJson('/api/forgot-password', ['email' => 'reset-me@example.com'])->assertOk();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $payload = [
            'token' => $token,
            'email' => 'reset-me@example.com',
            'password' => 'newpassword',
            'password_confirmation' => 'newpassword',
        ];

        $this->postJson('/api/reset-password', $payload)->assertOk();
        $this->postJson('/api/reset-password', $payload)->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_a_forged_token_and_a_mismatched_confirmation_are_both_refused(): void
    {
        User::factory()->create(['email' => 'reset-me@example.com', 'password' => 'oldpassword']);

        $this->postJson('/api/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'reset-me@example.com',
            'password' => 'newpassword',
            'password_confirmation' => 'newpassword',
        ])->assertStatus(422)->assertJsonPath('success', false);

        $this->postJson('/api/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'reset-me@example.com',
            'password' => 'newpassword',
            'password_confirmation' => 'a-different-password',
        ])->assertStatus(422);

        // Neither attempt may have touched the stored password.
        $this->postJson('/api/login', ['email' => 'reset-me@example.com', 'password' => 'oldpassword'])
            ->assertOk();
    }
}
