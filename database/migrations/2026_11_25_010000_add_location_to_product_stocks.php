<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->string('location', 100)->nullable()->after('qty');
            $table->index(['store_id', 'location'], 'product_stocks_store_location_idx');
        });
    }

    public function down(): void
    {
        Schema::table('product_stocks', function (Blueprint $table) {
            $table->dropIndex('product_stocks_store_location_idx');
            $table->dropColumn('location');
        });
    }
};
