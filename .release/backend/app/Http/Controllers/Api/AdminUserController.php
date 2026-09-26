<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        return response()->json(['data' => User::query()
            ->whereIn('role', ['admin', 'support'])
            ->select(['id', 'name', 'email', 'role', 'status', 'created_at'])
            ->latest()->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(['admin', 'support'])],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => trim($data['name']),
            'email' => $data['email'],
            'role' => $data['role'],
            'password' => $data['password'],
            'status' => 'active',
        ]);

        return response()->json(['data' => $this->summary($user)], 201);
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $this->ensureAdmin($request);
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);
        abort_if($user->id === $request->user()->id && $data['status'] === 'inactive', 422, 'You cannot deactivate your own account.');

        $user = DB::transaction(function () use ($request, $user, $data): User {
            if ($user->role === 'admin' && $data['status'] === 'inactive') {
                $admins = User::query()->where('role', 'admin')->orderBy('id')->lockForUpdate()->get();
                abort_unless($admins->firstWhere('id', $request->user()->id)?->isActive(), 403, 'This account is not active.');
                abort_if($admins->where('status', 'active')->count() <= 1, 422, 'The last active admin cannot be deactivated.');
            }

            $user->refresh();
            abort_if($user->status === $data['status'], 422, 'This account already has that status.');
            $user->update(['status' => $data['status']]);
            if ($data['status'] === 'inactive') {
                $user->tokens()->delete();
            }

            return $user;
        });

        return response()->json(['data' => $this->summary($user)]);
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Admin access is required.');
    }

    /** @return array<string, mixed> */
    private function summary(User $user): array
    {
        return $user->only(['id', 'name', 'email', 'role', 'status', 'created_at']);
    }
}
