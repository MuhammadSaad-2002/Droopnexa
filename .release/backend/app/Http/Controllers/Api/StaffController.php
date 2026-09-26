<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StaffController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $this->ensurePermission($request->user(), 'view_dashboard');

        return response()->json(['data' => [
            'viewer' => $request->user()->only(['id', 'name', 'email', 'role']),
            'permissions' => $request->user()->staffPermissions(),
            'customers' => User::where('role', 'customer')->count(),
            'pending_requests' => OrderRequest::whereIn('status', ['submitted', 'under_review', 'customer_contacted'])->count(),
            'confirmed_orders' => Order::whereIn('status', ['confirmed', 'processing'])->count(),
            'completed_orders' => Order::where('status', 'completed')->count(),
            'cashback_posted' => (float) DB::table('wallet_transactions')->where('type', 'cashback')->sum('amount'),
        ]]);
    }

    public function customers(Request $request): JsonResponse
    {
        $this->ensurePermission($request->user(), 'view_customers');

        $customers = User::query()
            ->where('role', 'customer')
            ->with(['customerProfile:id,user_id,phone', 'wallet:id,customer_id,cached_balance'])
            ->withCount([
                'orders as total_orders_count',
                'orders as completed_orders_count' => fn ($query) => $query->where('status', 'completed'),
            ])
            ->latest()
            ->paginate(20);

        return response()->json($customers);
    }

    public function showCustomer(Request $request, User $customer): JsonResponse
    {
        $this->ensurePermission($request->user(), 'view_customers');
        abort_unless($customer->role === 'customer', 404);

        $customer->load('customerProfile');
        $orders = $customer->orders()
            ->with(['product:id,title,category,icon', 'request:id,reference', 'statusHistory'])
            ->latest()
            ->get();
        $orderRequests = $customer->orderRequests()
            ->with(['items.product:id,title,category,icon', 'order:id,reference,status,product_id'])
            ->latest('submitted_at')
            ->get();
        $wallet = Wallet::query()->where('customer_id', $customer->id)->first();
        $transactions = WalletTransaction::query()
            ->where('customer_id', $customer->id)
            ->with(['order:id,reference', 'withdrawalRequest:id,reference'])
            ->latest()
            ->get();
        $withdrawals = $customer->withdrawalRequests()->latest()->get();

        $credits = $transactions->where('direction', 'credit')->sum('amount');
        $cashback = $transactions->where('type', 'cashback')->sum('amount');
        $redeemed = $transactions->where('type', 'order_redemption')->sum('amount');
        $withdrawn = $transactions->where('type', 'withdrawal')->sum('amount');
        $pendingWithdrawals = $withdrawals
            ->whereIn('status', ['pending', 'under_review', 'approved'])
            ->sum('amount');

        return response()->json(['data' => [
            'customer' => [
                ...$customer->only(['id', 'name', 'first_name', 'last_name', 'username', 'email', 'status', 'created_at']),
                'profile' => $customer->customerProfile,
            ],
            'summary' => [
                'orders_count' => $orders->count(),
                'completed_orders_count' => $orders->where('status', 'completed')->count(),
                'requests_count' => $orderRequests->count(),
            ],
            'wallet' => [
                'balance' => number_format((float) ($wallet?->cached_balance ?? 0), 2, '.', ''),
                'total_credits' => number_format((float) $credits, 2, '.', ''),
                'cashback_amount' => number_format((float) $cashback, 2, '.', ''),
                'redeemed_amount' => number_format((float) $redeemed, 2, '.', ''),
                'withdrawn_amount' => number_format((float) $withdrawn, 2, '.', ''),
                'pending_withdrawals_amount' => number_format((float) $pendingWithdrawals, 2, '.', ''),
                'transactions' => $transactions,
            ],
            'orders' => $orders,
            'requests' => $orderRequests,
            'withdrawals' => $withdrawals,
        ]]);
    }

    public function resetCustomerPassword(Request $request, User $customer): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Admin access is required.');
        abort_unless($customer->role === 'customer', 404);
        $data = $request->validate(['password' => ['required', 'string', 'min:8', 'confirmed']]);
        DB::transaction(function () use ($customer, $data): void {
            $customer->update(['password' => $data['password'], 'remember_token' => null]);
            $customer->tokens()->delete();
        });

        return response()->json(['message' => 'Customer password reset. Existing sessions have been signed out.']);
    }

    public function updateCustomerStatus(Request $request, User $customer): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Admin access is required.');
        abort_unless($customer->role === 'customer', 404);

        $data = $request->validate(['status' => ['required', 'in:active,banned']]);
        abort_unless(
            ($customer->status === 'active' && $data['status'] === 'banned') ||
            ($customer->status === 'banned' && $data['status'] === 'active'),
            422,
            'This account status cannot be changed with this action.'
        );

        $customer->update(['status' => $data['status']]);
        if ($data['status'] === 'banned') {
            $customer->tokens()->delete();
        }

        return response()->json(['data' => $customer->only(['id', 'name', 'email', 'status'])]);
    }

    public function requests(Request $request): JsonResponse
    {
        $this->ensurePermission($request->user(), 'manage_requests');
        $requests = OrderRequest::whereIn('status', ['submitted', 'under_review', 'customer_contacted'])
            ->with(['customer:id,name,email', 'items.product:id,title,slug,category,display_price,icon', 'order:id,reference,status'])
            ->latest('submitted_at')->paginate(12);

        return response()->json($requests);
    }

    public function showRequest(Request $request, OrderRequest $orderRequest): JsonResponse
    {
        $this->ensurePermission($request->user(), 'manage_requests');

        return response()->json(['data' => $orderRequest->load(['customer:id,name,email', 'items.product', 'order.statusHistory'])]);
    }

    public function finalize(Request $request, OrderRequest $orderRequest): JsonResponse
    {
        $this->ensurePermission($request->user(), 'manage_requests');
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'order_total' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        abort_unless(in_array($orderRequest->status, ['submitted', 'under_review', 'customer_contacted'], true), 422, 'This request is no longer available for finalization.');
        $item = $orderRequest->items()->where('product_id', $data['product_id'])->firstOrFail();
        abort_if($orderRequest->order()->exists(), 422, 'This request has already been finalized.');

        $order = DB::transaction(function () use ($data, $orderRequest, $item, $request) {
            $order = Order::create([
                'reference' => 'ORD-'.strtoupper(Str::random(8)),
                'customer_id' => $orderRequest->customer_id,
                'order_request_id' => $orderRequest->id,
                'product_id' => $item->product_id,
                'product_title_snapshot' => $item->product_title_snapshot,
                'order_total' => $data['order_total'],
                'external_amount_due' => $data['order_total'],
                'payment_method' => $data['payment_method'] ?? null,
                'notes' => $data['notes'] ?? null,
                'payment_status' => 'unpaid',
                'status' => 'confirmed',
                'confirmed_by' => $request->user()->id,
                'confirmed_at' => now(),
            ]);

            $order->statusHistory()->create([
                'from_status' => null,
                'to_status' => 'confirmed',
                'note' => 'Order finalized from customer request.',
                'changed_by' => $request->user()->id,
            ]);

            $orderRequest->update(['status' => 'product_finalized', 'reviewed_by' => $request->user()->id]);

            return $order->load(['customer:id,name,email', 'product', 'statusHistory']);
        });

        return response()->json(['data' => $order], 201);
    }

    public function orders(Request $request): JsonResponse
    {
        $this->ensurePermission($request->user(), 'manage_orders');
        $orders = Order::with('customer:id,name,email')->latest()->paginate(12);

        return response()->json($orders);
    }

    public function showOrder(Request $request, Order $order): JsonResponse
    {
        $this->ensurePermission($request->user(), 'manage_orders');
        $order->load(['customer:id,name,email', 'product', 'request.items.product', 'statusHistory.changedBy:id,name', 'walletTransactions']);
        $reserved = WithdrawalRequest::where('customer_id', $order->customer_id)->whereIn('status', ['pending', 'under_review', 'approved'])->sum('amount');
        $wallet = Wallet::firstOrCreate(['customer_id' => $order->customer_id]);
        $completedOrders = Order::query()->where('customer_id', $order->customer_id)->where('status', 'completed')->count();
        $minimumCompletedOrders = config('droopnexa.minimum_completed_orders_for_wallet_redemption', 3);

        return response()->json([
            'data' => $order,
            'meta' => [
                'wallet' => [
                    'balance' => (string) $wallet->cached_balance,
                    'available_balance' => number_format(max(0, (float) $wallet->cached_balance - (float) $reserved), 2, '.', ''),
                    'eligible' => $completedOrders >= $minimumCompletedOrders,
                    'completed_orders' => $completedOrders,
                    'minimum_completed_orders' => $minimumCompletedOrders,
                ],
            ],
        ]);
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $this->ensurePermission($request->user(), 'manage_orders');
        $data = $request->validate([
            'status' => ['required', 'in:confirmed,processing,completed,cancelled,rejected'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        abort_if($order->status === $data['status'], 422, 'The order is already in that status.');
        $allowedTransitions = [
            'confirmed' => ['processing', 'cancelled', 'rejected'],
            'processing' => ['completed', 'cancelled'],
            'completed' => [],
            'cancelled' => [],
            'rejected' => [],
        ];
        abort_unless(in_array($data['status'], $allowedTransitions[$order->status] ?? [], true), 422, 'That status change is not available from the current order status.');
        $updated = DB::transaction(function () use ($data, $order, $request) {
            $fromStatus = $order->status;
            $order->update([
                'status' => $data['status'],
                'completed_at' => $data['status'] === 'completed' ? now() : $order->completed_at,
            ]);
            $order->statusHistory()->create([
                'from_status' => $fromStatus,
                'to_status' => $data['status'],
                'note' => $data['note'] ?? null,
                'changed_by' => $request->user()->id,
            ]);

            return $order->fresh(['customer:id,name,email', 'product', 'statusHistory']);
        });

        return response()->json(['data' => $updated]);
    }

    public function addCashback(Request $request, Order $order, WalletService $walletService): JsonResponse
    {
        $this->ensurePermission($request->user(), 'manage_orders');
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.01']]);
        $transaction = $walletService->creditCashback($order, (string) $data['amount'], $request->user());

        return response()->json(['data' => $transaction->load('order')], 201);
    }

    public function redeemWallet(Request $request, Order $order, WalletService $walletService): JsonResponse
    {
        $this->ensurePermission($request->user(), 'manage_orders');
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.01']]);
        $transaction = $walletService->redeemAgainstOrder($order, (string) $data['amount'], $request->user());

        return response()->json(['data' => $transaction->load('order')], 201);
    }

    private function ensureStaff(User $user): void
    {
        abort_unless($user->isStaff(), 403, 'Staff access is required.');
    }

    private function ensurePermission(User $user, string $permission): void
    {
        $this->ensureStaff($user);
        abort_unless($user->hasStaffPermission($permission), 403, 'You do not have permission to perform this action.');
    }
}
