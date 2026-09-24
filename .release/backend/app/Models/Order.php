<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'customer_id',
        'order_request_id',
        'product_id',
        'product_title_snapshot',
        'order_total',
        'wallet_amount_used',
        'external_amount_due',
        'cashback_amount',
        'payment_status',
        'status',
        'payment_method',
        'notes',
        'confirmed_by',
        'confirmed_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'order_total' => 'decimal:2',
            'wallet_amount_used' => 'decimal:2',
            'external_amount_due' => 'decimal:2',
            'cashback_amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function request()
    {
        return $this->belongsTo(OrderRequest::class, 'order_request_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function statusHistory()
    {
        return $this->hasMany(OrderStatusHistory::class)->latest();
    }

    public function walletTransactions()
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
