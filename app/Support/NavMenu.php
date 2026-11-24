<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Navigasi sidebar berbasis aktivitas bisnis (bukan controller).
 * Struktur: grup -> item {label, icon, route, roles, badge}.
 * - roles: null = semua peran login; array = hanya peran tsb.
 * - badge: key penghitung di badgeCounts().
 */
class NavMenu
{
    public static function groups(): array
    {
        $mgr = ['superadmin', 'owner'];
        $admin = ['superadmin', 'owner', 'admin'];
        $ops = ['superadmin', 'owner', 'admin', 'kasir'];
        $gudang = ['superadmin', 'owner', 'admin', 'gudang'];

        return [
            [
                'label' => 'Overview', 'icon' => 'ti-dashboard',
                'items' => [
                    ['label' => 'Dashboard', 'icon' => 'ti-dashboard', 'route' => 'dashboard', 'roles' => null, 'match' => ['dashboard']],
                    ['label' => 'Monitoring Cabang', 'icon' => 'ti-building', 'route' => 'stores.monitor', 'roles' => $mgr, 'match' => ['stores.monitor']],
                    ['label' => 'Aktivitas', 'icon' => 'ti-history', 'route' => 'activity', 'roles' => null, 'match' => ['activity', 'activity.log']],
                    ['label' => 'Pusat Pengingat', 'icon' => 'ti-bell', 'route' => 'reminders.index', 'roles' => $admin, 'match' => ['reminders.index'], 'badge' => 'reminders'],
                ],
            ],
            [
                'label' => 'Penjualan', 'icon' => 'ti-shopping-cart',
                'items' => [
                    ['label' => 'POS / Kasir', 'icon' => 'ti-device-desktop', 'route' => 'pos.index', 'roles' => $ops, 'match' => ['pos.index', 'pos.print']],
                    ['label' => 'Riwayat Transaksi', 'icon' => 'ti-receipt', 'route' => 'sales.index', 'roles' => $ops, 'match' => ['sales.index', 'sales.show', 'sales.invoice', 'sales.pay', 'sales.closing']],
                    ['label' => 'Retur & Refund', 'icon' => 'ti-arrow-back-up', 'route' => 'returns.index', 'roles' => $ops, 'match' => ['returns.index']],
                    ['label' => 'Tukar Tambah', 'icon' => 'ti-repeat', 'route' => 'tradeins.index', 'roles' => $ops, 'match' => ['tradeins.index']],
                ],
            ],
            [
                'label' => 'Service Center', 'icon' => 'ti-tool',
                'items' => [
                    ['label' => 'Tiket Service', 'icon' => 'ti-ticket', 'route' => 'services.index', 'roles' => null, 'match' => ['services.index', 'services.show', 'services.edit', 'services.print'], 'badge' => 'service_ready'],
                    ['label' => 'Kanban', 'icon' => 'ti-kanban', 'route' => 'services.kanban', 'roles' => null, 'match' => ['services.kanban']],
                    ['label' => 'Terima HP Baru', 'icon' => 'ti-plus', 'route' => 'services.create', 'roles' => $ops, 'match' => ['services.create']],
                    ['label' => 'Laporan Service', 'icon' => 'ti-chart-bar', 'route' => 'services.report', 'roles' => $admin, 'match' => ['services.report']],
                    ['label' => 'Booking Online', 'icon' => 'ti-calendar', 'route' => 'bookings.index', 'roles' => null, 'match' => ['bookings.index', 'bookings.calendar'], 'badge' => 'booking_waiting'],
                    ['label' => 'Kalender', 'icon' => 'ti-calendar-month', 'route' => 'bookings.calendar', 'roles' => null, 'match' => []],
                    ['label' => 'Garansi', 'icon' => 'ti-shield', 'route' => 'warranties.index', 'roles' => null, 'match' => ['warranties.index']],
                ],
            ],
            [
                'label' => 'Inventory', 'icon' => 'ti-box',
                'items' => [
                    ['label' => 'Produk & HP', 'icon' => 'ti-package', 'route' => 'products.index', 'roles' => null, 'match' => ['products.index', 'products.show', 'products.create', 'products.edit', 'products.bulk']],
                    ['label' => 'Unit / IMEI', 'icon' => 'ti-barcode', 'route' => 'units.index', 'roles' => null, 'match' => ['units.index']],
                    ['label' => 'Saran Belanja', 'icon' => 'ti-bulb', 'route' => 'products.reorder', 'roles' => $gudang, 'match' => ['products.reorder'], 'badge' => 'low_stock'],
                    ['label' => 'Stok Opname', 'icon' => 'ti-clipboard-check', 'route' => 'stocks.opname', 'roles' => $gudang, 'match' => ['stocks.opname']],
                    ['label' => 'Mutasi & Transfer', 'icon' => 'ti-arrows-exchange', 'route' => 'stocks.mutations', 'roles' => $gudang, 'match' => ['stocks.mutations']],
                ],
            ],
            [
                'label' => 'Pembelian', 'icon' => 'ti-truck',
                'items' => [
                    ['label' => 'Supplier', 'icon' => 'ti-users', 'route' => 'suppliers.index', 'roles' => $admin, 'match' => ['suppliers.index']],
                    ['label' => 'Purchase Order', 'icon' => 'ti-file-text', 'route' => 'purchases.index', 'roles' => $admin, 'match' => ['purchases.index', 'purchases.show']],
                    ['label' => 'Beli HP Bekas', 'icon' => 'ti-device-mobile', 'route' => 'secondhand.index', 'roles' => $admin, 'match' => ['secondhand.index']],
                    ['label' => 'Konsinyasi', 'icon' => 'ti-handshake', 'route' => 'consignments.index', 'roles' => $admin, 'match' => ['consignments.index']],
                ],
            ],
            [
                'label' => 'Pelanggan', 'icon' => 'ti-heart',
                'items' => [
                    ['label' => 'CRM Pelanggan', 'icon' => 'ti-users', 'route' => 'customers.index', 'roles' => null, 'match' => ['customers.index', 'customers.show', 'customers.create', 'customers.edit']],
                    ['label' => 'Siaran WA', 'icon' => 'ti-brand-whatsapp', 'route' => 'customers.broadcast', 'roles' => $admin, 'match' => ['customers.broadcast']],
                ],
            ],
            [
                'label' => 'Teknisi', 'icon' => 'ti-user-cog',
                'items' => [
                    ['label' => 'Data Teknisi', 'icon' => 'ti-users', 'route' => 'technicians.index', 'roles' => null, 'match' => ['technicians.index']],
                    ['label' => 'Komisi & Laporan', 'icon' => 'ti-cash', 'route' => 'technicians.report', 'roles' => null, 'match' => ['technicians.report']],
                ],
            ],
            [
                'label' => 'Keuangan', 'icon' => 'ti-wallet',
                'items' => [
                    ['label' => 'Kas Masuk/Keluar', 'icon' => 'ti-arrows-left-right', 'route' => 'cash.index', 'roles' => $admin, 'match' => ['cash.index']],
                    ['label' => 'Pengeluaran', 'icon' => 'ti-receipt-2', 'route' => 'expenses.index', 'roles' => $admin, 'match' => ['expenses.index']],
                    ['label' => 'Hutang & Piutang', 'icon' => 'ti-scale', 'route' => 'debts.index', 'roles' => $admin, 'match' => ['debts.index'], 'badge' => 'debts_due'],
                    ['label' => 'Arus Kas', 'icon' => 'ti-trending-up', 'route' => 'cash.flow', 'roles' => $admin, 'match' => ['cash.flow']],
                    ['label' => 'Tutup Kasir', 'icon' => 'ti-lock', 'route' => 'sales.closing', 'roles' => $ops, 'match' => []],
                ],
            ],
            [
                'label' => 'Marketing', 'icon' => 'ti-speakerphone',
                'items' => [
                    ['label' => 'Promo & Voucher', 'icon' => 'ti-tag', 'route' => 'promos.index', 'roles' => $admin, 'match' => ['promos.index', 'promos.create', 'promos.edit', 'promos.show']],
                    ['label' => 'Target Penjualan', 'icon' => 'ti-target', 'route' => 'stores.monitor', 'roles' => $mgr, 'match' => []],
                ],
            ],
            [
                'label' => 'Laporan', 'icon' => 'ti-report',
                'items' => [
                    ['label' => 'Laba Rugi', 'icon' => 'ti-chart-pie', 'route' => 'reports.profit', 'roles' => $admin, 'match' => ['reports.profit']],
                    ['label' => 'Kesehatan Data', 'icon' => 'ti-heartbeat', 'route' => 'health', 'roles' => $mgr, 'match' => ['health']],
                ],
            ],
            [
                'label' => 'Administrasi', 'icon' => 'ti-settings',
                'items' => [
                    ['label' => 'Pengguna & Role', 'icon' => 'ti-user', 'route' => 'users.index', 'roles' => $admin, 'match' => ['users.index']],
                    ['label' => 'Toko / Cabang', 'icon' => 'ti-building-store', 'route' => 'stores.index', 'roles' => $mgr, 'match' => ['stores.index']],
                    ['label' => 'Printer & Cetak', 'icon' => 'ti-printer', 'route' => 'settings.printers', 'roles' => $admin, 'match' => ['settings.printers']],
                    ['label' => 'WhatsApp', 'icon' => 'ti-brand-whatsapp', 'route' => 'settings.wa', 'roles' => $admin, 'match' => ['settings.wa']],
                    ['label' => 'Backup Data', 'icon' => 'ti-database', 'route' => 'backup.index', 'roles' => $mgr, 'match' => ['backup.index']],
                ],
            ],
        ];
    }

    /** Kelompok yang boleh dilihat peran ini (grup kosong disembunyikan). */
    public static function forRole(?string $role): array
    {
        $out = [];
        foreach (self::groups() as $g) {
            $items = array_values(array_filter($g['items'], fn($i) => empty($i['roles']) || in_array($role, $i['roles'], true)));
            if ($items) {
                $g['items'] = $items;
                $out[] = $g;
            }
        }
        return $out;
    }

    /** Hitungan pekerjaan tertunda (cache 60 dtk, gagal = 0 agar nav tak ikut 500). */
    public static function badges(?int $storeId): array
    {
        try {
            return Cache::remember('nav_badges_' . ($storeId ?? 0), 60, function () use ($storeId) {
                $b = ['booking_waiting' => 0, 'service_ready' => 0, 'low_stock' => 0, 'debts_due' => 0, 'reminders' => 0];
                try {
                    if (Schema::hasTable('bookings')) {
                        $b['booking_waiting'] = (int) \DB::table('bookings')
                            ->when($storeId, fn($q) => $q->where('store_id', $storeId))
                            ->where('status', 'menunggu')->count();
                    }
                    if (Schema::hasTable('service_tickets')) {
                        $b['service_ready'] = (int) \DB::table('service_tickets')
                            ->when($storeId, fn($q) => $q->where('store_id', $storeId))
                            ->where('status', 'siap_diambil')->count();
                    }
                    if (Schema::hasTable('product_stocks') && Schema::hasTable('products')) {
                        $b['low_stock'] = (int) \DB::table('product_stocks')
                            ->join('products', 'products.id', '=', 'product_stocks.product_id')
                            ->when($storeId, fn($q) => $q->where('product_stocks.store_id', $storeId))
                            ->whereRaw('product_stocks.qty <= COALESCE(product_stocks.min_stock, products.min_stock)')->count();
                    }
                    if (Schema::hasTable('debts')) {
                        $b['debts_due'] = (int) \DB::table('debts')
                            ->when($storeId, fn($q) => $q->where('store_id', $storeId))
                            ->where('status', '!=', 'lunas')->whereNotNull('due_date')
                            ->whereDate('due_date', '<=', now()->addDays(7)->toDateString())->count();
                    }
                    $b['reminders'] = $b['booking_waiting'] + $b['service_ready'] + $b['debts_due'];
                } catch (\Throwable $e) {
                }
                return $b;
            });
        } catch (\Throwable $e) {
            return ['booking_waiting' => 0, 'service_ready' => 0, 'low_stock' => 0, 'debts_due' => 0, 'reminders' => 0];
        }
    }

    /** URL aman untuk item (route hilang = null agar tak 500). */
    public static function url(array $item): ?string
    {
        try {
            if (!\Route::has($item['route'])) return null;
            $params = [];
            return route($item['route'], array_merge($params, $item['query'] ?? []));
        } catch (\Throwable $e) {
            return null;
        }
    }
}
