<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerPortalController extends Controller
{
    public function completeAccount(Request $request): JsonResponse
    {
        $customer = $request->user();
        $this->ensureCustomer($customer);

        if ($request->has('username') && is_string($request->input('username'))) {
            $request->merge(['username' => Str::lower(trim($request->input('username')))]);
        }

        $data = $request->validate([
            'username' => ['sometimes', 'required', 'string', 'min:3', 'max:40', 'alpha_dash', 'unique:users,username'],
            'first_name' => ['sometimes', 'required', 'string', 'max:80'],
            'last_name' => ['sometimes', 'required', 'string', 'max:80'],
        ]);

        $locked = [];
        foreach (['username', 'first_name', 'last_name'] as $field) {
            if (array_key_exists($field, $data) && filled($customer->{$field})) {
                $locked[$field] = ['This field has already been added.'];
            }
        }
        if ($locked !== []) {
            throw ValidationException::withMessages($locked);
        }

        if (isset($data['first_name']) || isset($data['last_name'])) {
            $data['name'] = trim(($data['first_name'] ?? $customer->first_name ?? '').' '.($data['last_name'] ?? $customer->last_name ?? ''));
        }
        $customer->update($data);

        return response()->json([
            'message' => 'Your account details have been saved.',
            'data' => ['user' => $customer->fresh()->only(['id', 'name', 'first_name', 'last_name', 'username', 'email', 'role', 'status'])],
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $customer = $request->user();
        $this->ensureCustomer($customer);

        $data = $request->validate([
            'phone' => ['present', 'nullable', 'string', 'max:40'],
            'address' => ['present', 'nullable', 'string', 'max:1000'],
            'country' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $profile = $customer->customerProfile()->updateOrCreate([], $data);

        return response()->json([
            'message' => 'Your contact details have been updated.',
            'data' => ['customer_profile' => $profile->only(['phone', 'address', 'country'])],
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $customer = $request->user();
        $this->ensureCustomer($customer);

        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $customer->update(['password' => $data['password']]);

        return response()->json(['message' => 'Your password has been updated.']);
    }

    public function summary(Request $request): JsonResponse
    {
        $customer = $request->user();
        $this->ensureCustomer($customer);
        $wallet = Wallet::firstOrCreate(['customer_id' => $customer->id]);
        $completedOrders = Order::where('customer_id', $customer->id)->where('status', 'completed')->count();
        $minimumCompletedOrders = config('droopnexa.minimum_completed_orders_for_wallet_redemption', 3);

        $reserved = WithdrawalRequest::where('customer_id', $customer->id)->whereIn('status', ['pending', 'under_review', 'approved'])->sum('amount');

        return response()->json(['data' => [
            'customer' => $customer->only(['id', 'name', 'email']),
            'wallet' => [
                'balance' => (string) $wallet->cached_balance,
                'reserved_balance' => number_format((float) $reserved, 2, '.', ''),
                'available_balance' => number_format(max(0, (float) $wallet->cached_balance - (float) $reserved), 2, '.', ''),
                'eligible' => $completedOrders >= $minimumCompletedOrders,
                'transactions' => $wallet->transactions()->with(['order:id,reference', 'withdrawalRequest:id,reference'])->latest()->limit(10)->get(),
            ],
            'completed_orders' => $completedOrders,
            'completed_orders_threshold' => $minimumCompletedOrders,
            'requests' => OrderRequest::where('customer_id', $customer->id)->with('items')->latest()->limit(10)->get(),
            'orders' => Order::where('customer_id', $customer->id)->with(['product', 'statusHistory'])->latest()->limit(10)->get(),
            'withdrawals' => WithdrawalRequest::where('customer_id', $customer->id)->with('walletTransaction:id,withdrawal_request_id,reference')->latest()->get(),
        ]]);
    }

    public function orders(Request $request): JsonResponse
    {
        $this->ensureCustomer($request->user());
        $orders = Order::query()
            ->where('customer_id', $request->user()->id)
            ->with(['product', 'request.items.product', 'statusHistory.changedBy:id,name', 'walletTransactions'])
            ->latest()
            ->paginate(10);

        return response()->json($orders);
    }

    public function showOrder(Request $request, Order $order): JsonResponse
    {
        $this->ensureCustomer($request->user());
        abort_unless($order->customer_id === $request->user()->id, 404);

        return response()->json([
            'data' => $order->load([
                'product',
                'request.items.product',
                'statusHistory.changedBy:id,name',
                'walletTransactions',
            ]),
        ]);
    }

    private function ensureCustomer(User $user): void
    {
        abort_unless($user->role === 'customer', 403, 'Customer access is required.');
    }
}
