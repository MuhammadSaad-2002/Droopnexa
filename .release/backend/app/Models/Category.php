<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'is_active',
        'image_url',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category', 'name');
    }

    public function getImageUrlAttribute(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $host = strtolower((string) parse_url($value, PHP_URL_HOST));
        $path = (string) parse_url($value, PHP_URL_PATH);
        $appHost = strtolower((string) parse_url(config('app.url'), PHP_URL_HOST));
        if (str_starts_with($path, '/storage/') && (in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || $host === $appHost)) {
            return rtrim(config('app.url'), '/').$path;
        }

        return $value;
    }
}
