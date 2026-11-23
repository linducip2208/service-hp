<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_tickets', function (Blueprint $table) {
            $table->dateTime('estimated_done_at')->nullable()->after('taken_at');
            $table->index('estimated_done_at');
        });
        // Backfill tiket lama yang belum punya estimasi: terima + 2 hari
        try {
            \DB::table('service_tickets')->whereNull('estimated_done_at')
                ->update(['estimated_done_at' => \DB::raw('DATE_ADD(created_at, INTERVAL 2 DAY)')]);
        } catch (\Throwable $e) {}
    }

    public function down(): void
    {
        Schema::table('service_tickets', function (Blueprint $table) {
            $table->dropIndex(['estimated_done_at']);
            $table->dropColumn('estimated_done_at');
        });
    }
};
