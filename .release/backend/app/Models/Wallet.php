<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    protected $fillable = ['customer_id', 'cached_balance'];

    protected function casts(): array
    {
        return ['cached_balance' => 'decimal:2'];
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function transactions()
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
