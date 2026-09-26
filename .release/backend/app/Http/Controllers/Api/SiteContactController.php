<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteContactSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteContactController extends Controller
{
    public function show(): JsonResponse
    {
        $contact = SiteContactSetting::query()->first();

        return response()->json(['data' => $contact?->only(['phone', 'whatsapp', 'email']) ?? [
            'phone' => null,
            'whatsapp' => null,
            'email' => null,
        ]])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Admin access is required.');

        $data = $request->validate([
            'phone' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^\+?[0-9() .-]{7,40}$/'],
            'whatsapp' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[1-9][0-9]{6,14}$/'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ]);

        $contact = SiteContactSetting::query()->firstOrCreate([]);
        $contact->update($data);

        return response()->json(['data' => $contact->only(['phone', 'whatsapp', 'email'])])->header('Cache-Control', 'no-store');
    }
}
