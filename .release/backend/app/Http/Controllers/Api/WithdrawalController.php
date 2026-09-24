<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
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
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_details' => ['required', 'string', 'max:4000'],
            'customer_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $withdrawal = DB::transaction(function () use ($customer, $data) {
            $completedOrders = Order::query()->where('customer_id', $customer->id)->where('status', 'completed')->count();
            abort_unless($completedOrders >= config('droopnexa.minimum_completed_orders_for_wallet_redemption', 3), 422, 'Complete more orders before requesting a withdrawal.');

            $wallet = $customer->wallet()->lockForUpdate()->firstOrCreate([], ['cached_balance' => 0]);
            $reservedAmount = WithdrawalRequest::query()
                ->where('customer_id', $customer->id)
                ->whereIn('status', ['pending', 'under_review', 'approved'])
                ->sum('amount');
            $requestedAmount = (float) $data['amount'];

            abort_if((float) $wallet->cached_balance - (float) $reservedAmount < $requestedAmount, 422, 'The requested amount is greater than the available wallet balance.');

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
        abort_unless($withdrawalRequest->customer_id === $request->user()->id, 404);

        return response()->json(['data' => $withdrawalRequest->load('walletTransaction')]);
    }

    public function staffIndex(Request $request): JsonResponse
    {
        $this->ensureAdmin($request->user());

        return response()->json([
            'data' => WithdrawalRequest::query()
                ->with(['customer:id,name,email', 'customer.wallet:id,customer_id,cached_balance', 'reviewedBy:id,name', 'processedBy:id,name', 'walletTransaction:id,withdrawal_request_id,reference'])
                ->latest()
                ->get(),
        ]);
    }

    public function approve(Request $request, WithdrawalRequest $withdrawalRequest): JsonResponse
    {
        $this->ensureAdmin($request->user());
        abort_unless(in_array($withdrawalRequest->status, ['pending', 'under_review'], true), 422, 'This withdrawal cannot be approved in its current status.');

        $withdrawalRequest->update([
            'status' => 'approved',
            'admin_note' => $request->validate(['admin_note' => ['nullable', 'string', 'max:2000']])['admin_note'] ?? $withdrawalRequest->admin_note,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return response()->json(['data' => $withdrawalRequest->fresh(['customer:id,name,email', 'reviewedBy:id,name'])]);
    }

    public function reject(Request $request, WithdrawalRequest $withdrawalRequest): JsonResponse
    {
        $this->ensureAdmin($request->user());
        abort_unless(in_array($withdrawalRequest->status, ['pending', 'under_review', 'approved'], true), 422, 'This withdrawal cannot be rejected in its current status.');
        $data = $request->validate(['admin_note' => ['required', 'string', 'max:2000']]);

        $withdrawalRequest->update([
            'status' => 'rejected',
            'admin_note' => $data['admin_note'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return response()->json(['data' => $withdrawalRequest->fresh(['customer:id,name,email', 'reviewedBy:id,name'])]);
    }

    public function process(Request $request, WithdrawalRequest $withdrawalRequest, WalletService $walletService): JsonResponse
    {
        $this->ensureAdmin($request->user());
        $transaction = $walletService->processWithdrawal($withdrawalRequest, $request->user());

        return response()->json(['data' => $transaction], 201);
    }

    private function ensureCustomer(User $user): void
    {
        abort_unless($user->role === 'customer', 403, 'Customer access is required.');
    }

    private function ensureAdmin(User $user): void
    {
        abort_unless($user->isAdmin(), 403, 'Admin access is required.');
    }
}
