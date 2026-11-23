<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('imei')->nullable()->unique();
            $table->string('serial')->nullable();
            $table->enum('condition', ['baru', 'bekas', 'refurbished', 'konsinyasi'])->default('baru');
            $table->decimal('purchase_price', 15, 2)->default(0);
            $table->decimal('selling_price', 15, 2)->default(0);
            $table->enum('status', ['tersedia', 'terjual', 'service', 'mutasi', 'retur', 'konsinyasi'])->default('tersedia');
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('consignment_id')->nullable();
            $table->integer('warranty_months')->default(0);
            $table->timestamps();
            $table->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_items');
    }
};
