<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    protected $fillable = [
        'wallet_id',
        'customer_id',
        'reference',
        'direction',
        'type',
        'amount',
        'balance_after',
        'order_id',
        'withdrawal_request_id',
        'created_by',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function withdrawalRequest()
    {
        return $this->belongsTo(WithdrawalRequest::class);
    }
}
