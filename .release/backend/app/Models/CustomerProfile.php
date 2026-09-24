<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerProfile extends Model
{
    protected $fillable = [
        'user_id',
        'phone',
        'address',
        'country',
        'notes',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
