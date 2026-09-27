<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $base = rtrim(config('app.url'), '/');
        $entries = [['path' => '/', 'modified' => null], ['path' => '/collections', 'modified' => null]];
        foreach (Category::where('is_active', true)->get(['name', 'updated_at']) as $category) {
            $entries[] = ['path' => '/collections/'.rawurlencode(Str::slug($category->name)), 'modified' => $category->updated_at?->toAtomString()];
        }
        foreach (Product::visible()->get(['slug', 'updated_at']) as $product) {
            $entries[] = ['path' => '/products/'.rawurlencode($product->slug), 'modified' => $product->updated_at?->toAtomString()];
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($entries as $entry) {
            $xml .= '<url><loc>'.htmlspecialchars($base.$entry['path'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc>';
            if ($entry['modified']) {
                $xml .= '<lastmod>'.$entry['modified'].'</lastmod>';
            }
            $xml .= '</url>';
        }

        return response($xml.'</urlset>', 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
