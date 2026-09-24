<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrderRequest;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureCustomer($request);
        $requests = OrderRequest::query()
            ->with('items.product')
            ->where('customer_id', $request->user()->id)
            ->latest('submitted_at')
            ->paginate(10);

        return response()->json($requests);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureCustomer($request);
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:20'],
            'product_ids.*' => ['integer', 'distinct', 'exists:products,id'],
            'customer_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $products = Product::query()
            ->visible()
            ->whereIn('id', $data['product_ids'])
            ->get()
            ->keyBy('id');

        abort_if($products->count() !== count($data['product_ids']), 422, 'One or more selected products are unavailable.');

        $orderRequest = DB::transaction(function () use ($data, $products, $request) {
            $orderRequest = OrderRequest::create([
                'reference' => 'REQ-'.strtoupper(Str::random(8)),
                'customer_id' => $request->user()->id,
                'status' => 'submitted',
                'customer_note' => $data['customer_note'] ?? null,
                'submitted_at' => now(),
            ]);

            foreach ($data['product_ids'] as $position => $productId) {
                $product = $products->get($productId);
                $orderRequest->items()->create([
                    'product_id' => $product->id,
                    'product_title_snapshot' => $product->title,
                    'display_price_snapshot' => $product->sale_price ?? $product->display_price,
                    'position' => $position,
                ]);
            }

            return $orderRequest->load('items.product');
        });

        return response()->json(['data' => $orderRequest], 201);
    }

    public function show(Request $request, OrderRequest $orderRequest): JsonResponse
    {
        $this->ensureCustomer($request);
        abort_unless($orderRequest->customer_id === $request->user()->id, 404);

        return response()->json([
            'data' => $orderRequest->load('items.product'),
        ]);
    }

    private function ensureCustomer(Request $request): void
    {
        abort_unless($request->user()->role === 'customer', 403, 'Customer access is required.');
    }
}
