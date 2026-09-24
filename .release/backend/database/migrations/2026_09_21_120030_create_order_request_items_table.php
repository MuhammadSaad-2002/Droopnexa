<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_title_snapshot');
            $table->decimal('display_price_snapshot', 12, 2)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['order_request_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_request_items');
    }
};
