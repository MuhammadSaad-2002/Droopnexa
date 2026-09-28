<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('order_request_id', 'orders_order_request_id_index');
            $table->dropUnique('orders_order_request_id_unique');
        });

        DB::table('order_requests')->where('status', 'product_finalized')->orderBy('id')->chunkById(100, function ($requests): void {
            foreach ($requests as $request) {
                $selectedProducts = DB::table('order_request_items')->where('order_request_id', $request->id)->pluck('product_id');
                $orderedProducts = DB::table('orders')->where('order_request_id', $request->id)
                    ->whereNotIn('status', ['cancelled', 'rejected'])->pluck('product_id')->unique();
                if ($selectedProducts->count() > $orderedProducts->intersect($selectedProducts)->count()) {
                    DB::table('order_requests')->where('id', $request->id)->update([
                        'status' => $orderedProducts->isEmpty() ? 'under_review' : 'partially_ordered',
                    ]);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('orders')->select('order_request_id')->groupBy('order_request_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot restore the one-order-per-request constraint while requests have multiple orders.');
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->unique('order_request_id', 'orders_order_request_id_unique');
            $table->dropIndex('orders_order_request_id_index');
        });
    }
};
