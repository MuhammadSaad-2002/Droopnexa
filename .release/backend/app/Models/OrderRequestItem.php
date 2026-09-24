<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderRequestItem extends Model
{
    protected $fillable = [
        'order_request_id',
        'product_id',
        'product_title_snapshot',
        'display_price_snapshot',
        'position',
    ];

    protected function casts(): array
    {
        return ['display_price_snapshot' => 'decimal:2'];
    }

    public function request()
    {
        return $this->belongsTo(OrderRequest::class, 'order_request_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
