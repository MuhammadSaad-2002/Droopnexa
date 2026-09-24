<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            [
                'title' => 'Women Small Crossbody Bags',
                'category' => 'Fashion',
                'icon' => '▱',
                'display_price' => 45.99,
                'description' => 'A lightweight everyday crossbody bag with an adjustable strap, compact storage, and a simple gold-buckle finish.',
                'metadata' => ['image_url' => 'https://droplino.shop/wp-content/uploads/2026/05/Ladies-Bag.jpg', 'source' => 'droplino.shop'],
            ],
            [
                'title' => 'Tablet',
                'category' => 'Mobile Accessories',
                'icon' => '▣',
                'display_price' => 325.99,
                'description' => 'A versatile tablet for browsing, streaming, reading, and everyday mobile work.',
                'metadata' => ['image_url' => 'https://droplino.shop/wp-content/uploads/2026/02/Tablet.webp', 'source' => 'droplino.shop'],
            ],
            [
                'title' => "Women's Hoodie",
                'category' => "Women's Fashion",
                'icon' => '◒',
                'display_price' => 58.99,
                'description' => 'A comfortable everyday hoodie for relaxed layering and casual wear.',
                'metadata' => ['image_url' => 'https://droplino.shop/wp-content/uploads/2026/02/Womens-Hoodie.jpg', 'source' => 'droplino.shop'],
            ],
            [
                'title' => 'Lounge Accent Chair Living Room',
                'category' => 'Furniture',
                'icon' => '▰',
                'display_price' => 199.00,
                'description' => 'A statement accent chair designed to add a comfortable seat and a polished touch to the living room.',
                'metadata' => ['image_url' => 'https://droplino.shop/wp-content/uploads/2026/01/fpn-1-3-1.png', 'source' => 'droplino.shop'],
            ],
            [
                'title' => 'Microsoft Xbox Wireless Controller – Robot White',
                'category' => 'Toys & Video Games',
                'icon' => '⌁',
                'display_price' => 94.14,
                'description' => 'A wireless Xbox controller in Robot White for console and PC gaming.',
                'metadata' => ['image_url' => 'https://droplino.shop/wp-content/uploads/2026/01/1-76.jpg', 'source' => 'droplino.shop'],
            ],
            [
                'title' => 'Ocean Puzzle',
                'category' => 'Toys & Video Games',
                'icon' => '◎',
                'display_price' => 38.99,
                'description' => 'An ocean-themed puzzle for relaxed, screen-free play.',
                'metadata' => ['image_url' => 'https://droplino.shop/wp-content/uploads/2026/02/PuzzlePuzzle.jpg', 'source' => 'droplino.shop'],
            ],
            [
                'title' => 'Kids Sweater',
                'category' => 'Baby & Kids',
                'icon' => '◇',
                'display_price' => 42.99,
                'description' => 'A warm everyday sweater for kids, made for comfortable layering.',
                'metadata' => ['image_url' => 'https://droplino.shop/wp-content/uploads/2026/02/Kids-Sweater.jpg', 'source' => 'droplino.shop'],
            ],
            [
                'title' => 'Remote Control Cars',
                'category' => 'Baby & Kids',
                'icon' => '↗',
                'display_price' => 93.87,
                'description' => 'A fun remote-control car set built for active indoor and outdoor play.',
                'metadata' => ['image_url' => 'https://droplino.shop/wp-content/uploads/2026/02/Remote-Control-Car.webp', 'source' => 'droplino.shop'],
            ],
            [
                'title' => 'Smart Watch',
                'category' => 'Smart Wearables',
                'tag' => 'Flat 30% cashback',
                'icon' => '◌',
                'display_price' => 45.00,
                'original_price' => 100.00,
                'sale_price' => 45.00,
                'description' => 'A versatile everyday smartwatch with activity tracking, notifications, and a comfortable black strap.',
                'metadata' => [
                    'source' => 'upload',
                    'image_url' => 'http://127.0.0.1:8000/storage/products/Maek7mGjCuFqjlvHLnPqaZK7E1nsOXssiR4kPKtG.jpg',
                    'images' => ['http://127.0.0.1:8000/storage/products/Maek7mGjCuFqjlvHLnPqaZK7E1nsOXssiR4kPKtG.jpg'],
                ],
            ],
        ];

        collect($products)->pluck('category')->unique()->each(function (string $name): void {
            Category::updateOrCreate(
                ['name' => $name],
                ['slug' => str($name)->slug(), 'is_active' => true],
            );
        });

        $activeSlugs = collect($products)->map(fn (array $product) => str($product['title'])->slug())->all();
        Product::query()->whereNotIn('slug', $activeSlugs)->update(['is_active' => false, 'is_visible' => false]);

        foreach ($products as $product) {
            Product::updateOrCreate(
                ['slug' => str($product['title'])->slug()],
                $product + ['currency' => 'USD', 'is_active' => true, 'is_visible' => true],
            );
        }
    }
}
