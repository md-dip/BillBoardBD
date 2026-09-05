<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\ForgotPasswordRequest;
use App\Http\Requests\Shared\LoginRequest;
use App\Http\Requests\Shared\RegisterRequest;
use App\Http\Requests\Shared\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    /**
     * Demo accounts seeded via UserSeeder.
     * These emails log in with ANY password so reviewers can try all 3 roles
     * without needing a real password. Every other account still needs its
     * real password checked via Auth::attempt() below.
     */
    private const DEMO_EMAILS = [
        'client@test.com',
        'owner@test.com',
        'owner2@test.com',
        'admin@test.com',
    ];

    /**
     * Register a new user and return an API token.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::query()->create([
            'name'     => $request->validated('name'),
            'email'    => $request->validated('email'),
            'password' => $request->validated('password'),
            'phone'    => $request->validated('phone'),
            'role'     => $request->validated('role') ?? 'client',
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'data'    => ['user' => $user, 'token' => $token],
            'message' => 'Registered successfully',
        ], 201);
    }

    /**
     * Log in and return an API token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $isDemoAccount = in_array($email, self::DEMO_EMAILS, true);

        if ($isDemoAccount) {
            // Demo email: skip password check, just look up the user.
            $user = User::query()->where('email', $email)->first();

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'data'    => null,
                    'message' => 'Invalid credentials',
                ], 401);
            }
        } elseif (! Auth::attempt($request->only('email', 'password'))) {
            // Real user: verify email + password against database.
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'Invalid credentials',
            ], 401);
        }

        /** @var User $user */
        $user = User::query()->where('email', $email)->firstOrFail();
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'data'    => ['user' => $user, 'token' => $token],
            'message' => 'Logged in successfully',
        ]);
    }

    /**
     * Log out by deleting the current token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'data'    => null,
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Return the currently authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $request->user(),
            'message' => null,
        ]);
    }

    /**
     * Email a password-reset link.
     *
     * Password::sendResetLink() does the whole job: it finds the user, mints a
     * token into password_reset_tokens, throttles repeat requests (60s, see
     * config/auth.php) and fires the ResetPassword notification. The link it
     * puts in the mail points at our React app, not a Laravel route - that URL
     * is built by the createUrlUsing() callback in AppServiceProvider.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->only('email'));

        // Deliberately the same answer whether or not that email has an
        // account. Reporting 'no such user' here would turn this endpoint into
        // a way to test which addresses are registered, so the status from
        // sendResetLink() is intentionally not surfaced.
        return response()->json([
            'success' => true,
            'data'    => null,
            'message' => 'If that email has an account, a reset link is on its way.',
        ]);
    }

    /**
     * Set a new password using the token from the emailed link.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                // 'password' is a hashed cast on User, so assigning the plain
                // string here stores a hash.
                $user->forceFill(['password' => $password])->save();

                // Drop every existing API token: if the reset was triggered
                // because the account was compromised, leaving the intruder's
                // token alive would make the reset pointless.
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PasswordReset) {
            // Wrong / expired / already-used token, or an email that does not
            // match the token. __($status) turns Laravel's status key into the
            // human message from lang/.
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => __($status),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data'    => null,
            'message' => 'Password reset successfully. You can log in now.',
        ]);
    }
}