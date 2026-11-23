<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['superadmin', 'owner', 'admin', 'kasir', 'teknisi', 'gudang'])->default('kasir')->after('password');
            $table->string('phone')->nullable()->after('role');
            $table->foreignId('active_store_id')->nullable()->after('phone')->constrained('stores')->nullOnDelete();
            $table->decimal('commission_rate', 5, 2)->default(0)->after('active_store_id');
            $table->boolean('is_active')->default(true)->after('commission_rate');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_store_id');
            $table->dropColumn(['role', 'phone', 'commission_rate', 'is_active']);
        });
    }
};
