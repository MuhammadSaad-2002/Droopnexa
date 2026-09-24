<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureProductPermission($request);

        return response()->json([
            'data' => Category::query()->withCount('products')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureProductPermission($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', 'unique:categories,name'],
        ]);

        $category = Category::create([
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($data['name']),
            'is_active' => true,
        ]);

        return response()->json(['data' => $category->loadCount('products')], 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $this->ensureProductPermission($request);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80', Rule::unique('categories', 'name')->ignore($category->id)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('is_active', $data) && ! $data['is_active'] && $category->products()->exists()) {
            abort(422, 'Move the products out of this category before archiving it.');
        }

        DB::transaction(function () use ($category, $data): void {
            $oldName = $category->name;
            if (array_key_exists('name', $data) && $data['name'] !== $oldName) {
                Product::query()->where('category', $oldName)->update(['category' => $data['name']]);
                $data['slug'] = $this->uniqueSlug($data['name'], $category->id);
            }
            $category->update($data);
        });

        return response()->json(['data' => $category->fresh()->loadCount('products')]);
    }

    public function destroy(Request $request, Category $category): JsonResponse
    {
        $this->ensureProductPermission($request);

        if ($category->products()->exists()) {
            abort(422, 'Move the products out of this category before archiving it.');
        }

        $category->update(['is_active' => false]);

        return response()->json(['data' => $category->fresh()->loadCount('products'), 'message' => 'Category archived successfully.']);
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $suffix = 2;

        while (Category::query()->where('slug', $slug)->when($ignoreId, fn ($query) => $query->where('id', '<>', $ignoreId))->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function ensureProductPermission(Request $request): void
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Admin access is required.');
    }
}
