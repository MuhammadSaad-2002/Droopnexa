<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderRequest extends Model
{
    use HasFactory;

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

    public function order()
    {
        return $this->hasOne(Order::class);
    }
}
