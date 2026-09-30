<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->visible()
            ->when($request->string('category')->isNotEmpty(), function ($query) use ($request) {
                $query->where('category', $request->string('category')->toString());
            })
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request) {
                $search = $request->string('search')->toString();
                $query->where(function ($nested) use ($search) {
                    $nested->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderBy('category')
            ->orderBy('title')
            ->get();

        return response()->json(['data' => $products]);
    }

    public function show(Product $product): JsonResponse
    {
        abort_unless($product->is_active && $product->is_visible, 404);

        return response()->json(['data' => $product]);
    }

    public function staffIndex(Request $request): JsonResponse
    {
        $this->ensureProductPermission($request);

        return response()->json([
            'data' => Product::query()->latest()->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureProductPermission($request);

        $data = $this->validatedData($request, true);
        $data['currency'] ??= 'USD';
        $product = Product::create($data + ['slug' => $this->uniqueSlug($data['title'])]);

        return response()->json(['data' => $product], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->ensureProductPermission($request);

        $data = $this->validatedData($request, false, $product);
        if (array_key_exists('title', $data) && $data['title'] !== $product->title) {
            $data['slug'] = $this->uniqueSlug($data['title'], $product->id);
        }

        $product->update($data);

        return response()->json(['data' => $product->fresh()]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->ensureProductPermission($request);
        $product->delete();

        return response()->json(['message' => 'Product archived successfully.']);
    }

    private function validatedData(Request $request, bool $creating = false, ?Product $product = null): array
    {
        $data = $request->validate([
            'title' => array_merge($creating ? ['required'] : ['sometimes'], ['string', 'max:160']),
            'category' => array_merge($creating ? ['required'] : ['sometimes'], ['string', 'max:80', Rule::exists('categories', 'name')->where(fn ($query) => $query->where('is_active', true))]),
            'tag' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:5000'],
            'display_price' => ['nullable', 'numeric', 'min:0'],
            'original_price' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'icon' => ['nullable', 'string', 'max:8'],
            'is_active' => ['sometimes', 'boolean'],
            'is_visible' => ['sometimes', 'boolean'],
            'metadata' => ['nullable', 'array'],
            'images' => ['sometimes', 'array', 'max:5'],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'sync_images' => ['sometimes', 'boolean'],
            'retained_images' => ['sometimes', 'array', 'max:5'],
            'retained_images.*' => ['string', 'max:2048', 'distinct'],
        ]);

        $hasOriginalPrice = array_key_exists('original_price', $data);
        $hasSalePrice = array_key_exists('sale_price', $data);
        if ($hasOriginalPrice && $hasSalePrice && $data['original_price'] !== null && $data['sale_price'] !== null && (float) $data['sale_price'] > (float) $data['original_price']) {
            throw ValidationException::withMessages(['sale_price' => ['Sale price cannot be higher than the original price.']]);
        }

        if ($hasSalePrice) {
            $data['display_price'] = $data['sale_price'] ?? ($data['original_price'] ?? null);
        } elseif (array_key_exists('display_price', $data)) {
            $data['sale_price'] = $data['display_price'];
        }

        $uploads = $request->file('images');
        $uploads = is_array($uploads) ? $uploads : ($uploads ? [$uploads] : []);
        $syncImages = (bool) ($data['sync_images'] ?? false);
        $submittedRetainedImages = $data['retained_images'] ?? [];
        unset($data['images'], $data['sync_images'], $data['retained_images']);

        if ($uploads !== [] || $syncImages) {
            $existingMetadata = is_array($product?->metadata) ? $product->metadata : [];
            $submittedMetadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
            $existingImages = is_array($existingMetadata['images'] ?? null) && $existingMetadata['images'] !== []
                ? $existingMetadata['images']
                : array_filter([$existingMetadata['image_url'] ?? null]);
            $existingImages = array_values(array_filter($existingImages, 'is_string'));

            if ($syncImages && $product === null) {
                throw ValidationException::withMessages(['retained_images' => ['Images can only be retained when updating a product.']]);
            }

            if ($syncImages && array_diff($submittedRetainedImages, $existingImages) !== []) {
                throw ValidationException::withMessages(['retained_images' => ['Only images already attached to this product can be retained.']]);
            }

            $retainedImageUrls = $syncImages ? $submittedRetainedImages : $existingImages;
            $imageUrls = $retainedImageUrls;
            if (count($imageUrls) + count($uploads) > 5) {
                throw ValidationException::withMessages(['images' => ['Remove an existing image before uploading another. A product can have up to 5 images.']]);
            }

            foreach ($uploads as $upload) {
                $path = Storage::disk('public')->putFile('products', $upload);
                if (! is_string($path)) {
                    abort(500, 'The product image could not be stored.');
                }
                $imageUrls[] = Storage::disk('public')->url($path);
            }

            $data['metadata'] = array_merge(
                $existingMetadata,
                $submittedMetadata,
                [
                    'source' => $imageUrls === [] ? null : ($retainedImageUrls !== [] ? ($existingMetadata['source'] ?? 'upload') : 'upload'),
                    'image_url' => $imageUrls[0] ?? null,
                    'images' => $imageUrls,
                ],
            );
        }

        return $data;
    }

    private function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'product';
        $slug = $base;
        $suffix = 2;

        while (Product::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($query) => $query->where('id', '<>', $ignoreId))->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function ensureProductPermission(Request $request): void
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Admin access is required.');
    }
}
