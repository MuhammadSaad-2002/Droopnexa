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

    public function update(Request $request, User $user): JsonResponse
    {
        $this->ensureAdmin($request);
        abort_unless(in_array($user->role, ['admin', 'support'], true), 404);
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in(['admin', 'support'])],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);
        DB::transaction(function () use ($request, $user, $data): void {
            $admins = User::where('role', 'admin')->orderBy('id')->lockForUpdate()->get();
            $actor = $admins->firstWhere('id', $request->user()->id);
            abort_unless($actor?->isActive(), 403, 'Admin access is required.');
            $user->refresh();
            if ($user->role === 'admin' && $data['role'] !== 'admin') {
                abort_if($user->id === $actor->id, 422, 'You cannot change your own admin role.');
                abort_if($user->isActive() && $admins->where('status', 'active')->count() <= 1, 422, 'The last active admin must keep admin access.');
            }
            $changes = ['name' => trim($data['name']), 'email' => $data['email'], 'role' => $data['role']];
            if (! empty($data['password'])) {
                $changes['password'] = $data['password'];
            }
            $revoke = ! empty($data['password']) || $user->role !== $data['role'];
            $user->update($changes);
            if ($revoke) {
                $user->tokens()->delete();
            }
        });

        return response()->json(['data' => $this->summary($user)]);
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
