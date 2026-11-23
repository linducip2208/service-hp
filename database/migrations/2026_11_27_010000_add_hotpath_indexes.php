<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index jalur panas (hot path) untuk query digest per toko per hari
     * dan anti-ganda WA:
     * wa_logs(store_id, type, created_at + type, phone),
     * bookings(store_id, status, schedule_date),
     * warranties(status, end_date),
     * service_logs(service_ticket_id, to_status),
     * technician_commissions(technician_id, status),
     * cash_transactions(ref_type, ref_id),
     * debts(ref_type, ref_id).
     *
     * Dilewati bila index sudah ada (aman dijalankan ulang).
     * sale_items.sale_id dan service_spareparts.service_ticket_id
     * sengaja tidak ditambah: sudah terindeks via foreign key.
     */
    public function up(): void
    {
        $this->addIndex('wa_logs', 'wa_store_type_created_idx', ['store_id', 'type', 'created_at']);
        $this->addIndex('wa_logs', 'wa_type_phone_idx', ['type', 'phone']);
        $this->addIndex('bookings', 'bookings_store_status_sched_idx', ['store_id', 'status', 'schedule_date']);
        $this->addIndex('warranties', 'warranties_status_end_idx', ['status', 'end_date']);
        $this->addIndex('service_logs', 'svc_logs_ticket_to_idx', ['service_ticket_id', 'to_status']);
        $this->addIndex('technician_commissions', 'techcomm_tech_status_idx', ['technician_id', 'status']);
        $this->addIndex('cash_transactions', 'cash_ref_idx', ['ref_type', 'ref_id']);
        $this->addIndex('debts', 'debts_ref_idx', ['ref_type', 'ref_id']);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('wa_logs', 'wa_store_type_created_idx');
        $this->dropIndexIfExists('wa_logs', 'wa_type_phone_idx');
        $this->dropIndexIfExists('bookings', 'bookings_store_status_sched_idx');
        $this->dropIndexIfExists('warranties', 'warranties_status_end_idx');
        $this->dropIndexIfExists('service_logs', 'svc_logs_ticket_to_idx');
        $this->dropIndexIfExists('technician_commissions', 'techcomm_tech_status_idx');
        $this->dropIndexIfExists('cash_transactions', 'cash_ref_idx');
        $this->dropIndexIfExists('debts', 'debts_ref_idx');
    }

    private function existingIndexNames(string $table): array
    {
        try {
            return collect(Schema::getIndexes($table))->pluck('name')->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function addIndex(string $table, string $name, array $columns): void
    {
        if (!Schema::hasTable($table)) return;
        foreach ($columns as $col) {
            if (!Schema::hasColumn($table, $col)) return;
        }
        if (in_array($name, $this->existingIndexNames($table), true)) return;
        Schema::table($table, function (Blueprint $t) use ($name, $columns) {
            $t->index($columns, $name);
        });
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (!Schema::hasTable($table)) return;
        if (!in_array($name, $this->existingIndexNames($table), true)) return;
        Schema::table($table, function (Blueprint $t) use ($name) {
            $t->dropIndex($name);
        });
    }
};
