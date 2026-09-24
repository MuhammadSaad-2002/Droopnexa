<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'slug',
        'category',
        'tag',
        'description',
        'display_price',
        'original_price',
        'sale_price',
        'currency',
        'icon',
        'is_active',
        'is_visible',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'display_price' => 'decimal:2',
            'original_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'is_active' => 'boolean',
            'is_visible' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function scopeVisible($query)
    {
        return $query->where('is_active', true)->where('is_visible', true);
    }
}
