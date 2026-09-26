<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WithdrawalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureCustomer($request->user());

        return response()->json([
            'data' => WithdrawalRequest::query()->where('customer_id', $request->user()->id)->latest()->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $customer = $request->user();
        $this->ensureCustomer($customer);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
            'payment_details' => ['required', 'string', 'max:4000'],
            'customer_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $withdrawal = DB::transaction(function () use ($customer, $data) {
            $wallet = $customer->wallet()->lockForUpdate()->firstOrCreate([], ['cached_balance' => 0]);
            $completedOrders = Order::query()->where('customer_id', $customer->id)->where('status', 'completed')->count();
            abort_unless($completedOrders >= config('droopnexa.minimum_completed_orders_for_wallet_redemption', 3), 422, 'Complete more orders before requesting a withdrawal.');

            $reservedAmount = WithdrawalRequest::query()
                ->where('customer_id', $customer->id)
                ->whereIn('status', ['pending', 'under_review', 'approved'])
                ->lockForUpdate()->get()->sum('amount');
            $requestedAmount = (float) $data['amount'];

            abort_if((int) round((float) $wallet->cached_balance * 100) - (int) round((float) $reservedAmount * 100) < (int) round($requestedAmount * 100), 422, 'The requested amount is greater than the available wallet balance.');

            return WithdrawalRequest::create([
                'reference' => 'WDR-'.strtoupper(Str::random(8)),
                'customer_id' => $customer->id,
                'amount' => $requestedAmount,
                'status' => 'pending',
                'payment_details' => $data['payment_details'],
                'customer_note' => $data['customer_note'] ?? null,
            ]);
        });

        return response()->json(['data' => $withdrawal], 201);
    }

    public function show(Request $request, WithdrawalRequest $withdrawalRequest): JsonResponse
    {
        $this->ensureCustomer($request->user());
        abort_unless($withdrawalRequest->customer_id === $request->user()->id, 404);

        return response()->json(['data' => $withdrawalRequest->load('walletTransaction')]);
    }

    public function staffIndex(Request $request): JsonResponse
    {
        $this->ensureStaff($request->user());

        return response()->json([
            'data' => WithdrawalRequest::query()
                ->with(['customer' => fn ($query) => $query->select('id', 'name', 'email')->withCount(['orders as completed_orders_count' => fn ($orders) => $orders->where('status', 'completed')]), 'customer.wallet:id,customer_id,cached_balance', 'reviewedBy:id,name', 'processedBy:id,name', 'walletTransaction:id,withdrawal_request_id,reference'])
                ->latest()
                ->get(),
        ]);
    }

    public function review(Request $request, WithdrawalRequest $withdrawalRequest): JsonResponse
    {
        $this->ensureStaff($request->user());

        return $this->transition($request, $withdrawalRequest, 'under_review', ['pending']);
    }

    public function approve(Request $request, WithdrawalRequest $withdrawalRequest): JsonResponse
    {
        $this->ensureStaff($request->user());

        return $this->transition($request, $withdrawalRequest, 'approved', ['pending', 'under_review']);
    }

    public function reject(Request $request, WithdrawalRequest $withdrawalRequest): JsonResponse
    {
        $this->ensureStaff($request->user());

        return $this->transition($request, $withdrawalRequest, 'rejected', ['pending', 'under_review', 'approved']);
    }

    public function cancel(Request $request, WithdrawalRequest $withdrawalRequest): JsonResponse
    {
        $this->ensureCustomer($request->user());
        abort_unless($withdrawalRequest->customer_id === $request->user()->id, 404);

        return $this->transition($request, $withdrawalRequest, 'cancelled', ['pending', 'under_review']);
    }

    private function transition(Request $request, WithdrawalRequest $withdrawal, string $status, array $allowed): JsonResponse
    {
        $data = $status === 'cancelled' ? [] : $request->validate([
            'admin_note' => [$status === 'rejected' ? 'required' : 'nullable', 'string', 'max:2000'],
        ]);
        $updated = DB::transaction(function () use ($request, $withdrawal, $status, $allowed, $data) {
            Wallet::query()->where('customer_id', $withdrawal->customer_id)->lockForUpdate()->firstOrFail();
            $locked = WithdrawalRequest::query()->lockForUpdate()->findOrFail($withdrawal->id);
            abort_unless(in_array($locked->status, $allowed, true), 422, 'This withdrawal has changed or cannot be updated in its current status.');
            $changes = ['status' => $status];
            if ($status !== 'cancelled') {
                $changes += ['admin_note' => $data['admin_note'] ?? $locked->admin_note, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()];
            }
            $locked->update($changes);

            return $locked->fresh();
        }, 3);

        return response()->json(['data' => $updated]);
    }

    public function process(Request $request, WithdrawalRequest $withdrawalRequest, WalletService $walletService): JsonResponse
    {
        $this->ensureStaff($request->user());
        $data = $request->validate(['payout_confirmed' => ['required', 'accepted'], 'admin_note' => ['required', 'string', 'max:2000']]);
        $transaction = $walletService->processWithdrawal($withdrawalRequest, $request->user(), $data['admin_note']);

        return response()->json(['data' => $transaction], 201);
    }

    private function ensureCustomer(User $user): void
    {
        abort_unless($user->role === 'customer', 403, 'Customer access is required.');
    }

    private function ensureStaff(User $user): void
    {
        abort_unless($user->hasStaffPermission('manage_withdrawals'), 403, 'Staff withdrawal access is required.');
    }
}
