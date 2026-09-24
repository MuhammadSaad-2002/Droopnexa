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

    /** Resolve imported local upload URLs against this installation. */
    public function getMetadataAttribute(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $metadata = json_decode($value, true);
        if (! is_array($metadata)) {
            return null;
        }

        $normalize = static function (mixed $url): mixed {
            if (! is_string($url)) {
                return $url;
            }
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) && str_starts_with($path, '/storage/')) {
                return rtrim(config('app.url'), '/').$path;
            }

            return $url;
        };
        if (isset($metadata['image_url'])) {
            $metadata['image_url'] = $normalize($metadata['image_url']);
        }
        if (isset($metadata['images']) && is_array($metadata['images'])) {
            $metadata['images'] = array_map($normalize, $metadata['images']);
        }

        return $metadata;
    }

    public function scopeVisible($query)
    {
        return $query->where('is_active', true)->where('is_visible', true);
    }
}
