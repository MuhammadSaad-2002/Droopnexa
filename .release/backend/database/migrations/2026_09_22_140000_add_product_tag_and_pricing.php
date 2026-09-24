<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('tag', 80)->nullable()->after('category');
            $table->decimal('original_price', 12, 2)->nullable()->after('display_price');
            $table->decimal('sale_price', 12, 2)->nullable()->after('original_price');
        });

        DB::table('products')->whereNull('sale_price')->update([
            'sale_price' => DB::raw('display_price'),
        ]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['tag', 'original_price', 'sale_price']);
        });
    }
};
