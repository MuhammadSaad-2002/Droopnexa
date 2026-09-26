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

class CustomerPortalController extends Controller
{
    public function updateProfile(Request $request): JsonResponse
    {
        $customer = $request->user();
        $this->ensureCustomer($customer);

        $data = $request->validate([
            'phone' => ['present', 'nullable', 'string', 'max:40'],
            'address' => ['present', 'nullable', 'string', 'max:1000'],
        ]);

        $profile = $customer->customerProfile()->updateOrCreate([], $data);

        return response()->json([
            'message' => 'Your contact details have been updated.',
            'data' => ['customer_profile' => $profile->only(['phone', 'address', 'country'])],
        ]);
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
