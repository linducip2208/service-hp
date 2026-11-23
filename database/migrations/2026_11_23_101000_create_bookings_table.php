<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('customer_name');
            $table->string('customer_phone')->nullable();
            $table->string('device')->nullable();
            $table->text('complaint')->nullable();
            $table->dateTime('schedule_date')->nullable();
            $table->enum('status', ['menunggu', 'dikonfirmasi', 'dibatalkan', 'selesai'])->default('menunggu');
            $table->unsignedBigInteger('ticket_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
