<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderRequest extends Model
{
    use HasFactory;

    public const OPEN_STATUSES = ['submitted', 'under_review', 'customer_contacted', 'partially_ordered'];

    protected $appends = ['ordered_count', 'remaining_count'];

    protected $fillable = [
        'reference',
        'customer_id',
        'status',
        'customer_note',
        'staff_note',
        'submitted_at',
        'reviewed_by',
    ];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime'];
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function items()
    {
        return $this->hasMany(OrderRequestItem::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class)->latest();
    }

    public function getOrderedCountAttribute(): int
    {
        $selected = $this->items->pluck('product_id');

        return $this->orders->whereNotIn('status', ['cancelled', 'rejected'])
            ->pluck('product_id')->unique()->intersect($selected)->count();
    }

    public function getRemainingCountAttribute(): int
    {
        return max(0, $this->items->count() - $this->ordered_count);
    }

    public function syncOrderProgress(): void
    {
        if ($this->status === 'rejected') {
            return;
        }

        $this->load(['items:id,order_request_id,product_id', 'orders:id,order_request_id,product_id,status']);
        $status = $this->remaining_count === 0 ? 'product_finalized'
            : ($this->ordered_count > 0 ? 'partially_ordered' : 'under_review');
        $this->update(['status' => $status]);
    }
}
