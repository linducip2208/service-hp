<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index komposit untuk kolom yang paling sering difilter/sort:
     * sales(store_id, created_at + payment_status),
     * service_tickets(store_id, status, technician_id),
     * stock_movements(product_id, created_at),
     * cash_transactions(store_id, type, category, created_at),
     * debts(store_id, status, due_date),
     * bookings(store_id, status).
     *
     * Dilewati bila index sudah ada (aman dijalankan ulang / bila putaran
     * sebelumnya sudah menambahkan sebagian index).
     */
    public function up(): void
    {
        $this->addIndex('sales', 'sales_store_created_idx', ['store_id', 'created_at']);
        $this->addIndex('sales', 'sales_payment_status_idx', ['payment_status']);
        $this->addIndex('service_tickets', 'svc_store_status_tech_idx', ['store_id', 'status', 'technician_id']);
        $this->addIndex('stock_movements', 'stock_mov_product_created_idx', ['product_id', 'created_at']);
        $this->addIndex('cash_transactions', 'cash_store_type_cat_created_idx', ['store_id', 'type', 'category', 'created_at']);
        $this->addIndex('debts', 'debts_store_status_due_idx', ['store_id', 'status', 'due_date']);
        $this->addIndex('bookings', 'bookings_store_status_idx', ['store_id', 'status']);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('sales', 'sales_store_created_idx');
        $this->dropIndexIfExists('sales', 'sales_payment_status_idx');
        $this->dropIndexIfExists('service_tickets', 'svc_store_status_tech_idx');
        $this->dropIndexIfExists('stock_movements', 'stock_mov_product_created_idx');
        $this->dropIndexIfExists('cash_transactions', 'cash_store_type_cat_created_idx');
        $this->dropIndexIfExists('debts', 'debts_store_status_due_idx');
        $this->dropIndexIfExists('bookings', 'bookings_store_status_idx');
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
