<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $request->merge([
            'username' => filled($request->input('username')) ? Str::lower(trim((string) $request->input('username'))) : null,
        ]);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'username' => ['nullable', 'string', 'min:3', 'max:40', 'alpha_dash', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:1000'],
            'country' => ['nullable', 'string', 'max:120'],
        ]);

        $user = User::create([
            'name' => trim($data['first_name'].' '.$data['last_name']),
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'username' => isset($data['username']) ? Str::lower($data['username']) : null,
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => 'customer',
            'status' => 'active',
        ]);
        CustomerProfile::create([
            'user_id' => $user->id,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'country' => $data['country'] ?? null,
        ]);
        Wallet::create(['customer_id' => $user->id]);

        return response()->json([
            'data' => $this->payload($user),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required_without:email', 'nullable', 'string'],
            'email' => ['required_without:login', 'nullable', 'email'],
            'password' => ['required', 'string'],
        ]);

        $login = trim((string) ($data['login'] ?? $data['email'] ?? ''));
        $user = User::query()
            ->where('email', $login)
            ->orWhere('username', Str::lower($login))
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages([
                'login' => ['This account is currently disabled.'],
            ]);
        }

        return response()->json(['data' => $this->payload($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out successfully.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('customerProfile');

        return response()->json([
            'data' => [
                'user' => array_merge(
                    $user->only(['id', 'name', 'first_name', 'last_name', 'username', 'email', 'role', 'status']),
                    ['customer_profile' => $user->customerProfile?->only(['phone', 'address', 'country'])],
                ),
            ],
        ]);
    }

    private function payload(User $user): array
    {
        $user->loadMissing('customerProfile');

        return [
            'user' => array_merge(
                $user->only(['id', 'name', 'first_name', 'last_name', 'username', 'email', 'role', 'status']),
                ['customer_profile' => $user->customerProfile?->only(['phone', 'address', 'country'])],
            ),
            'token' => $user->createToken('droopnexa-web')->plainTextToken,
        ];
    }
}
