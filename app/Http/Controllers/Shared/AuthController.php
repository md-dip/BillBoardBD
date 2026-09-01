<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\LoginRequest;
use App\Http\Requests\Shared\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
}