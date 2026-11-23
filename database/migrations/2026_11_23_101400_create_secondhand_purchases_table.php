<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secondhand_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('imei')->nullable();
            $table->enum('condition_grade', ['A', 'B', 'C', 'D'])->default('B');
            $table->decimal('buy_price', 15, 2)->default(0);
            $table->decimal('sell_price_plan', 15, 2)->default(0);
            $table->enum('status', ['dibeli', 'dijual', 'retur'])->default('dibeli');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secondhand_purchases');
    }
};
