<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_no')->unique();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('product_brand')->nullable();
            $table->string('product_type')->nullable();
            $table->string('imei')->nullable();
            $table->text('complaint');
            $table->text('condition_note')->nullable();
            $table->json('photos')->nullable();
            $table->text('diagnosis')->nullable();
            $table->decimal('estimate_cost', 15, 2)->default(0);
            $table->enum('approval_status', ['menunggu', 'disetujui', 'ditolak'])->default('menunggu');
            $table->enum('status', ['diterima', 'diagnosa', 'menunggu_persetujuan', 'disetujui', 'dikerjakan', 'qc', 'siap_diambil', 'diserahkan', 'batal'])->default('diterima');
            $table->enum('priority', ['rendah', 'normal', 'mendesak'])->default('normal');
            $table->decimal('dp', 15, 2)->default(0);
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->integer('warranty_days')->default(7);
            $table->dateTime('taken_at')->nullable();
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_tickets');
    }
};
