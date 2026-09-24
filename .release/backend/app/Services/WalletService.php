<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WalletService
{
    public function creditCashback(Order $order, string $amount, User $actor): WalletTransaction
    {
        return DB::transaction(function () use ($order, $amount, $actor) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($lockedOrder->status !== 'completed') {
                throw ValidationException::withMessages(['order' => ['Cashback can only be added to a completed order.']]);
            }

            if ((float) $lockedOrder->cashback_amount > 0 || WalletTransaction::query()->where('order_id', $lockedOrder->id)->where('type', 'cashback')->exists()) {
                throw ValidationException::withMessages(['order' => ['Cashback has already been added for this order.']]);
            }

            $numericAmount = (float) $amount;
            if ($numericAmount <= 0) {
                throw ValidationException::withMessages(['amount' => ['Cashback must be greater than zero.']]);
            }

            $wallet = Wallet::query()->firstOrCreate(['customer_id' => $lockedOrder->customer_id]);
            $wallet = Wallet::query()->lockForUpdate()->findOrFail($wallet->id);
            $balanceAfter = (float) $wallet->cached_balance + $numericAmount;

            $transaction = $wallet->transactions()->create([
                'customer_id' => $lockedOrder->customer_id,
                'reference' => 'WLT-'.strtoupper(Str::random(8)),
                'direction' => 'credit',
                'type' => 'cashback',
                'amount' => $numericAmount,
                'balance_after' => $balanceAfter,
                'order_id' => $lockedOrder->id,
                'created_by' => $actor->id,
                'description' => "Cashback for {$lockedOrder->reference}",
            ]);

            $wallet->update(['cached_balance' => $balanceAfter]);
            $lockedOrder->update(['cashback_amount' => $numericAmount]);

            return $transaction->load('order');
        });
    }

    public function processWithdrawal(WithdrawalRequest $withdrawal, User $actor): WalletTransaction
    {
        return DB::transaction(function () use ($withdrawal, $actor) {
            $lockedWithdrawal = WithdrawalRequest::query()->lockForUpdate()->findOrFail($withdrawal->id);

            if ($lockedWithdrawal->status !== 'approved') {
                throw ValidationException::withMessages(['withdrawal' => ['Only approved withdrawals can be processed.']]);
            }

            if ($lockedWithdrawal->walletTransaction()->exists()) {
                throw ValidationException::withMessages(['withdrawal' => ['This withdrawal has already been paid.']]);
            }

            $wallet = Wallet::query()->where('customer_id', $lockedWithdrawal->customer_id)->lockForUpdate()->firstOrFail();
            $numericAmount = (float) $lockedWithdrawal->amount;

            if ((float) $wallet->cached_balance < $numericAmount) {
                throw ValidationException::withMessages(['amount' => ['The customer no longer has enough wallet balance.']]);
            }

            $balanceAfter = (float) $wallet->cached_balance - $numericAmount;
            $transaction = $wallet->transactions()->create([
                'customer_id' => $lockedWithdrawal->customer_id,
                'reference' => 'WLT-'.strtoupper(Str::random(8)),
                'direction' => 'debit',
                'type' => 'withdrawal',
                'amount' => $numericAmount,
                'balance_after' => $balanceAfter,
                'withdrawal_request_id' => $lockedWithdrawal->id,
                'created_by' => $actor->id,
                'description' => "Withdrawal for {$lockedWithdrawal->reference}",
            ]);

            $wallet->update(['cached_balance' => $balanceAfter]);
            $lockedWithdrawal->update([
                'status' => 'paid',
                'processed_by' => $actor->id,
                'processed_at' => now(),
            ]);

            return $transaction->load('withdrawalRequest');
        });
    }

    public function redeemAgainstOrder(Order $order, string $amount, User $actor): WalletTransaction
    {
        return DB::transaction(function () use ($order, $amount, $actor) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (in_array($lockedOrder->status, ['completed', 'cancelled', 'rejected'], true)) {
                throw ValidationException::withMessages(['order' => ['Wallet credit cannot be applied to this order.']]);
            }

            if (WalletTransaction::query()->where('order_id', $lockedOrder->id)->where('type', 'order_redemption')->exists()) {
                throw ValidationException::withMessages(['order' => ['Wallet credit has already been applied to this order.']]);
            }

            $completedOrders = Order::query()->where('customer_id', $lockedOrder->customer_id)->where('status', 'completed')->count();
            if ($completedOrders < config('droopnexa.minimum_completed_orders_for_wallet_redemption', 3)) {
                throw ValidationException::withMessages(['order' => ['The customer is not eligible to use wallet credit yet.']]);
            }

            $numericAmount = (float) $amount;
            if ($numericAmount <= 0 || $numericAmount > (float) $lockedOrder->order_total) {
                throw ValidationException::withMessages(['amount' => ['Wallet credit must be greater than zero and no more than the order total.']]);
            }

            $wallet = Wallet::query()->where('customer_id', $lockedOrder->customer_id)->lockForUpdate()->firstOrFail();
            if ((float) $wallet->cached_balance < $numericAmount) {
                throw ValidationException::withMessages(['amount' => ['The customer does not have enough wallet balance.']]);
            }

            $balanceAfter = (float) $wallet->cached_balance - $numericAmount;
            $transaction = $wallet->transactions()->create([
                'customer_id' => $lockedOrder->customer_id,
                'reference' => 'WLT-'.strtoupper(Str::random(8)),
                'direction' => 'debit',
                'type' => 'order_redemption',
                'amount' => $numericAmount,
                'balance_after' => $balanceAfter,
                'order_id' => $lockedOrder->id,
                'created_by' => $actor->id,
                'description' => "Wallet credit used for {$lockedOrder->reference}",
            ]);

            $wallet->update(['cached_balance' => $balanceAfter]);
            $lockedOrder->update([
                'wallet_amount_used' => $numericAmount,
                'external_amount_due' => max((float) $lockedOrder->order_total - $numericAmount, 0),
                'payment_status' => $numericAmount >= (float) $lockedOrder->order_total ? 'paid' : 'partial',
            ]);

            return $transaction->load('order');
        });
    }
}
