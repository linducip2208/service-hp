<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\CashAccount;
use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\Debt;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Promo;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\ServiceSparepart;
use App\Models\ServiceTicket;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warranty;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * DemoTransactionSeeder — DATA DEMO KAYA untuk calon pembeli.
 *
 * NONAKTIF default (tidak dipanggil DatabaseSeeder).
 * Jalankan manual saat butuh showroom laporan:
 *   php artisan db:seed --class=DemoTransactionSeeder
 *
 * Idempoten: skip bila invoice prefix DEMO- sudah ada ATAU
 * flag file storage/app/demo_seeded ada. Semua nomor memakai
 * prefix DEMO- agar mudah dibersihkan:
 *   Sale::where('invoice_no','like','DEMO-%')->delete(); dst.
 *
 * Catatan: seeder ini SENGAJA tidak mengutak-atik stok
 * (ProductStock) — fokus ke keterbacaan laporan.
 */
class DemoTransactionSeeder extends Seeder
{
    public function run(): void
    {
        // Guard 1: flag file.
        $flag = storage_path('app/demo_seeded');
        // Guard 2: data DEMO- sudah ada.
        if (Sale::where('invoice_no', 'like', 'DEMO-%')->exists()) {
            $this->command?->warn('DemoTransactionSeeder: data DEMO-* sudah ada, skip.');

            return;
        }

        $pusat = Store::where('code', 'PST')->first() ?? Store::first();
        if (! $pusat) {
            $this->command?->error('DemoTransactionSeeder: tidak ada Store. Jalankan DatabaseSeeder dulu.');

            return;
        }

        $kasir = User::where('email', 'kasir@servicehp.id')->first() ?? User::first();
        $teknisi = User::where('email', 'teknisi@servicehp.id')->first() ?? $kasir;
        $tunai = CashAccount::where('store_id', $pusat->id)->where('name', 'Kas Tunai')->first()
            ?? CashAccount::where('store_id', $pusat->id)->first();
        $promo = Promo::where('code', 'WELCOME10')->first();
        $customers = Customer::where('store_id', $pusat->id)->get();
        if ($customers->isEmpty()) {
            $customers = Customer::take(3)->get();
        }

        $bySku = fn (string $sku) => Product::where('sku', $sku)->first();
        $day = fn (int $ago) => Carbon::now()->subDays($ago)->setTime(random_int(9, 20), random_int(0, 59), 0);

        // ------------------------------------------------ 15 PENJUALAN --
        // [sku, qty, method, status, promo?, tenor, ago]
        $plan = [
            ['HP-XMRN12-128', 1, 'tunai', 'lunas', false, 0, 29],
            ['AKS-CAS-UNI', 3, 'tunai', 'lunas', false, 0, 28],
            ['HP-SSA54-128', 1, 'transfer', 'lunas', true, 0, 26],
            ['AKS-CHR-25W', 2, 'qris', 'lunas', false, 0, 25],
            ['HP-IP14-128', 1, 'transfer', 'lunas', true, 0, 23],
            ['AKS-TG-UNI', 5, 'tunai', 'lunas', false, 0, 21],
            ['HP-SS23-256', 1, 'cicilan', 'cicilan', false, 6, 20],
            ['PLS-50K', 2, 'tunai', 'lunas', false, 0, 18],
            ['HP-XM13-256', 1, 'qris', 'lunas', true, 0, 16],
            ['AKS-CAS-UNI', 4, 'qris', 'lunas', false, 0, 14], // target retur
            ['HP-OPR11-256', 1, 'cicilan', 'cicilan', false, 12, 12], // cicilan sebagian
            ['PKT-30GB', 3, 'tunai', 'lunas', false, 0, 10],
            ['HP-VVV29-256', 1, 'transfer', 'lunas', true, 0, 7],
            ['SPR-BAT-SS', 2, 'tunai', 'lunas', false, 0, 4],
            ['HP-IP13-128-BKS', 1, 'transfer', 'lunas', false, 0, 1],
        ];

        $demoSales = [];
        foreach ($plan as $i => [$sku, $qty, $method, $status, $usePromo, $tenor, $ago]) {
            $n = $i + 1;
            $inv = sprintf('DEMO-JL-%03d', $n);
            if (Sale::where('invoice_no', $inv)->exists()) {
                $demoSales[] = Sale::where('invoice_no', $inv)->first();
                continue;
            }
            $product = $bySku($sku);
            if (! $product) {
                continue;
            }
            $price = (float) $product->selling_price;
            $subtotal = $price * $qty;
            $discount = $usePromo && $promo ? round($subtotal * 10 / 100) : 0;
            $total = $subtotal - $discount;
            $isInstallment = $method === 'cicilan';
            // Satu cicilan dibuat "sebagian": baru bayar ~40%.
            $partial = $n === 11;
            $paid = $isInstallment
                ? ($partial ? round($total * 0.4) : round($total / max($tenor, 1)))
                : $total;

            $sale = Sale::create([
                'invoice_no' => $inv,
                'store_id' => $pusat->id,
                'user_id' => $kasir?->id,
                'customer_id' => $customers->isNotEmpty() ? $customers[$i % $customers->count()]->id : null,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => 0,
                'total' => $total,
                'payment_method' => $method,
                'payment_status' => $isInstallment ? 'cicilan' : 'lunas',
                'paid_amount' => $paid,
                'remaining' => $total - $paid,
                'installment_tenor' => $isInstallment ? $tenor : 0,
                'promo_id' => $usePromo ? $promo?->id : null,
                'note' => 'Demo showroom — '.$inv,
            ]);
            $ts = $day($ago);
            $sale->created_at = $ts;
            $sale->updated_at = $ts;
            $sale->saveQuietly();

            $item = SaleItem::create([
                'sale_id' => $sale->id,
                'product_id' => $product->id,
                'description' => $product->name,
                'qty' => $qty,
                'price' => $price,
                'discount' => 0,
                'subtotal' => $subtotal,
                'profit' => ($price - (float) $product->purchase_price) * $qty,
            ]);
            $item->created_at = $ts;
            $item->updated_at = $ts;
            $item->saveQuietly();

            $pay = SalePayment::create([
                'sale_id' => $sale->id,
                'amount' => $paid,
                'method' => $method === 'cicilan' ? 'tunai' : $method,
                'note' => $isInstallment ? 'Demo: angsuran pertama '.$inv : 'Demo pelunasan '.$inv,
                'user_id' => $kasir?->id,
            ]);
            $pay->created_at = $ts;
            $pay->updated_at = $ts;
            $pay->saveQuietly();

            if ($tunai && $paid > 0) {
                $ct0 = CashTransaction::create([
                    'store_id' => $pusat->id,
                    'cash_account_id' => $tunai->id,
                    'type' => 'masuk',
                    'category' => 'penjualan',
                    'amount' => $paid,
                    'ref_type' => Sale::class,
                    'ref_id' => $sale->id,
                    'description' => 'Demo: penerimaan '.$inv,
                    'user_id' => $kasir?->id,
                ]);
                $ct0->created_at = $ts;
                $ct0->updated_at = $ts;
                $ct0->saveQuietly();
            }

            // Piutang untuk cicilan yang belum lunas.
            if ($isInstallment && ($total - $paid) > 0) {
                Debt::firstOrCreate(
                    ['store_id' => $pusat->id, 'type' => 'piutang', 'ref_type' => Sale::class, 'ref_id' => $sale->id],
                    [
                        'party_name' => $sale->customer?->name ?? 'Pelanggan demo',
                        'total' => $total,
                        'paid' => $paid,
                        'remaining' => $total - $paid,
                        'due_date' => Carbon::now()->addDays(30)->toDateString(),
                        'status' => 'sebagian',
                    ]
                );
            }

            $demoSales[] = $sale;
        }

        // ---- 1 RETUR atas penjualan DEMO-JL-010 (aksesoris 4 pcs -> retur 1 pc)
        $returSale = Sale::where('invoice_no', 'DEMO-JL-010')->first();
        if ($returSale && ! SaleReturn::where('sale_id', $returSale->id)->exists()) {
            $ri = $returSale->items()->first();
            $amt = $ri ? (float) $ri->price * 1 : 35000;
            $ts = $day(13);
            $ret = SaleReturn::create([
                'sale_id' => $returSale->id,
                'sale_item_id' => $ri?->id,
                'store_id' => $pusat->id,
                'amount' => $amt,
                'reason' => 'Demo: casing cacat, retur 1 pc',
                'type' => 'retur',
                'user_id' => $kasir?->id,
            ]);
            $ret->created_at = $ts;
            $ret->updated_at = $ts;
            $ret->saveQuietly();
            if ($tunai) {
                $ct = CashTransaction::create([
                    'store_id' => $pusat->id,
                    'cash_account_id' => $tunai->id,
                    'type' => 'keluar',
                    'category' => 'penjualan',
                    'amount' => $amt,
                    'ref_type' => SaleReturn::class,
                    'ref_id' => $ret->id,
                    'description' => 'Demo: refund retur DEMO-JL-010',
                    'user_id' => $kasir?->id,
                ]);
                $ct->created_at = $ts;
                $ct->updated_at = $ts;
                $ct->saveQuietly();
            }
        }

        // --------------------------------------------- 8 TIKET SERVICE --
        // [status, complaint, cost, dp, brand, ago, warranty?]
        $svcPlan = [
            ['diserahkan', 'Ganti LCD iPhone, sentuh kadang macet', 750000, 750000, 'Apple', 27, true],
            ['diserahkan', 'Baterai Samsung drop, ganti baterai', 220000, 220000, 'Samsung', 24, true],
            ['siap_diambil', 'Konektor charger Redmi kendor', 150000, 50000, 'Xiaomi', 9, false],
            ['siap_diambil', 'Oppo bootloop setelah update', 300000, 100000, 'Oppo', 3, false],
            ['dikerjakan', 'Vivo mati total, cek mesin', 0, 100000, 'Vivo', 2, false],
            ['diagnosa', 'iPhone 13 kamera berembun', 0, 0, 'Apple', 1, false],
            ['menunggu_persetujuan', 'Galaxy A54 sinyal hilang', 450000, 0, 'Samsung', 1, false],
            ['diterima', 'Redmi Note 12 tombol power keras', 0, 0, 'Xiaomi', 0, false],
        ];

        foreach ($svcPlan as $i => [$status, $complaint, $cost, $dp, $brand, $ago, $withWarranty]) {
            $tno = sprintf('DEMO-SVC-%03d', $i + 1);
            if (ServiceTicket::where('ticket_no', $tno)->exists()) {
                continue;
            }
            $ts = $day($ago);
            $t = ServiceTicket::create([
                'ticket_no' => $tno,
                'store_id' => $pusat->id,
                'customer_id' => $customers->isNotEmpty() ? $customers[$i % $customers->count()]->id : Customer::first()?->id,
                'technician_id' => $teknisi?->id,
                'product_brand' => $brand,
                'product_type' => $brand.' demo unit',
                'imei' => 'DEMO35975'.str_pad((string) ($i + 1), 6, '0', STR_PAD_LEFT),
                'complaint' => 'Demo: '.$complaint,
                'condition_note' => 'Demo showroom',
                'diagnosis' => in_array($status, ['diserahkan', 'siap_diambil', 'dikerjakan']) ? 'Demo: diagnosa teknisi tercatat' : null,
                'estimate_cost' => $cost,
                'approval_status' => $status === 'menunggu_persetujuan' ? 'menunggu' : 'disetujui',
                'status' => $status,
                'priority' => $i === 4 ? 'mendesak' : 'normal',
                'dp' => $dp,
                'total_cost' => in_array($status, ['diserahkan', 'siap_diambil']) ? $cost : $dp,
                'warranty_days' => 30,
                'taken_at' => $status === 'diserahkan' ? $ts->copy()->addDays(2) : null,
                'estimated_done_at' => $ts->copy()->addDays(3),
            ]);
            $t->created_at = $ts;
            $t->updated_at = $ts;
            $t->saveQuietly();

            // 1 tiket dengan sparepart: tiket ke-5 (dikerjakan) pakai LCD iPhone 11.
            if ($i === 4) {
                $sp = $bySku('SPR-LCD-IP11');
                if ($sp) {
                    ServiceSparepart::firstOrCreate(
                        ['service_ticket_id' => $t->id, 'product_id' => $sp->id],
                        ['qty' => 1, 'price' => (float) $sp->selling_price, 'subtotal' => (float) $sp->selling_price]
                    );
                }
            }

            // Kas masuk service untuk DP / pelunasan.
            $cashIn = $status === 'diserahkan' ? $cost : $dp;
            if ($tunai && $cashIn > 0) {
                $ct = CashTransaction::create([
                    'store_id' => $pusat->id,
                    'cash_account_id' => $tunai->id,
                    'type' => 'masuk',
                    'category' => 'service',
                    'amount' => $cashIn,
                    'ref_type' => ServiceTicket::class,
                    'ref_id' => $t->id,
                    'description' => 'Demo: penerimaan service '.$tno,
                    'user_id' => $kasir?->id,
                ]);
                $ct->created_at = $ts;
                $ct->updated_at = $ts;
                $ct->saveQuietly();
            }

            // Garansi service untuk 2 tiket yang diserahkan.
            if ($withWarranty) {
                Warranty::firstOrCreate(
                    ['ref_type' => ServiceTicket::class, 'ref_id' => $t->id],
                    [
                        'customer_id' => $t->customer_id,
                        'start_date' => $ts->toDateString(),
                        'end_date' => $ts->copy()->addDays(30)->toDateString(),
                        'type' => 'service',
                        'status' => 'aktif',
                    ]
                );
            }
        }

        // ------------------------------------------------- 3 PEMBELIAN --
        // [supplierIdx, status, paidRatio, items(sku=>qty), ago]
        $suppliers = Supplier::where('store_id', $pusat->id)->get();
        if ($suppliers->isEmpty()) {
            $suppliers = Supplier::take(3)->get();
        }
        $pbPlan = [
            [0, 'lunas', 1.0, ['AKS-CAS-UNI' => 50, 'AKS-TG-UNI' => 50], 22],
            [1, 'sebagian', 0.5, ['AKS-CHR-25W' => 20, 'SPR-BAT-SS' => 10], 11],
            [2, 'belum', 0.0, ['SPR-LCD-IP11' => 4], 5],
        ];
        foreach ($pbPlan as $i => [$sIdx, $status, $ratio, $items, $ago]) {
            $inv = sprintf('DEMO-PB-%03d', $i + 1);
            if (Purchase::where('invoice_no', $inv)->exists()) {
                continue;
            }
            $ts = $day($ago);
            $total = 0;
            foreach ($items as $sku => $qty) {
                $p = $bySku($sku);
                if ($p) {
                    $total += (float) $p->purchase_price * $qty;
                }
            }
            $paid = round($total * $ratio);
            $pb = Purchase::create([
                'invoice_no' => $inv,
                'store_id' => $pusat->id,
                'supplier_id' => $suppliers->isNotEmpty() ? $suppliers[$sIdx % $suppliers->count()]->id : null,
                'total' => $total,
                'paid' => $paid,
                'status' => $status,
                'user_id' => $kasir?->id,
                'note' => 'Demo pembelian supplier — '.$inv,
            ]);
            $pb->created_at = $ts;
            $pb->updated_at = $ts;
            $pb->saveQuietly();

            foreach ($items as $sku => $qty) {
                $p = $bySku($sku);
                if (! $p) {
                    continue;
                }
                $pi = PurchaseItem::create([
                    'purchase_id' => $pb->id,
                    'product_id' => $p->id,
                    'qty' => $qty,
                    'price' => (float) $p->purchase_price,
                    'subtotal' => (float) $p->purchase_price * $qty,
                ]);
                $pi->created_at = $ts;
                $pi->updated_at = $ts;
                $pi->saveQuietly();
            }

            if ($tunai && $paid > 0) {
                $ct = CashTransaction::create([
                    'store_id' => $pusat->id,
                    'cash_account_id' => $tunai->id,
                    'type' => 'keluar',
                    'category' => 'pembelian',
                    'amount' => $paid,
                    'ref_type' => Purchase::class,
                    'ref_id' => $pb->id,
                    'description' => 'Demo: bayar pembelian '.$inv,
                    'user_id' => $kasir?->id,
                ]);
                $ct->created_at = $ts;
                $ct->updated_at = $ts;
                $ct->saveQuietly();
            }

            if (($total - $paid) > 0) {
                Debt::firstOrCreate(
                    ['store_id' => $pusat->id, 'type' => 'hutang', 'ref_type' => Purchase::class, 'ref_id' => $pb->id],
                    [
                        'party_name' => $pb->supplier?->name ?? 'Supplier demo',
                        'total' => $total,
                        'paid' => $paid,
                        'remaining' => $total - $paid,
                        'due_date' => Carbon::now()->addDays(14)->toDateString(),
                        'status' => $status === 'belum' ? 'belum' : 'sebagian',
                    ]
                );
            }
        }

        // ---------------------------------------------------- 2 BOOKING --
        $bkPlan = [
            ['DEMO-BK-001', 'menunggu', 'Rina Marlina', '0812444444', 'Samsung A54 — layar retak', 1],
            ['DEMO-BK-002', 'selesai', 'Dedi Kurniawan', '0812555555', 'iPhone 11 — baterai kembung', 6],
        ];
        foreach ($bkPlan as [$code, $status, $name, $phone, $device, $ago]) {
            if (Booking::where('code', $code)->exists()) {
                continue;
            }
            $ts = $day($ago);
            $b = Booking::create([
                'code' => $code,
                'store_id' => $pusat->id,
                'customer_name' => $name,
                'customer_phone' => $phone,
                'device' => $device,
                'complaint' => 'Demo booking: '.$device,
                'schedule_date' => $ts->copy()->addDay(),
                'status' => $status,
            ]);
            $b->created_at = $ts;
            $b->updated_at = $ts;
            $b->saveQuietly();
        }

        // -------------------------------------- CASH / EXPENSE TAMBAHAN --
        $extraCash = [
            ['masuk', 'modal', 5000000, 'Demo: setoran modal awal showroom', 28],
            ['masuk', 'lain', 350000, 'Demo: jasa antar / komisi', 15],
        ];
        foreach ($extraCash as $k => [$type, $cat, $amt, $desc, $ago]) {
            $ts = $day($ago);
            $ct = CashTransaction::create([
                'store_id' => $pusat->id,
                'cash_account_id' => $tunai?->id,
                'type' => $type,
                'category' => $cat,
                'amount' => $amt,
                'description' => $desc,
                'user_id' => $kasir?->id,
            ]);
            $ct->created_at = $ts;
            $ct->updated_at = $ts;
            $ct->saveQuietly();
        }

        $expPlan = [
            ['Gaji', 3500000, 'Demo: gaji teknisi paruh bulan', 27],
            ['Sewa', 2000000, 'Demo: cicilan sewa ruko', 19],
            ['Listrik & Air', 650000, 'Demo: tagihan listrik + wifi', 8],
            ['Operasional', 450000, 'Demo: plastik, struk, ATK', 2],
        ];
        foreach ($expPlan as [$cat, $amt, $desc, $ago]) {
            $ts = $day($ago);
            $e = Expense::create([
                'store_id' => $pusat->id,
                'category' => $cat,
                'amount' => $amt,
                'description' => $desc,
                'expense_date' => $ts->toDateString(),
                'user_id' => $kasir?->id,
            ]);
            $e->created_at = $ts;
            $e->updated_at = $ts;
            $e->saveQuietly();
            if ($tunai) {
                $ct = CashTransaction::create([
                    'store_id' => $pusat->id,
                    'cash_account_id' => $tunai->id,
                    'type' => 'keluar',
                    'category' => 'pengeluaran',
                    'amount' => $amt,
                    'ref_type' => Expense::class,
                    'ref_id' => $e->id,
                    'description' => $desc,
                    'user_id' => $kasir?->id,
                ]);
                $ct->created_at = $ts;
                $ct->updated_at = $ts;
                $ct->saveQuietly();
            }
        }

        // Tandai selesai agar run berikutnya skip cepat.
        File::ensureDirectoryExists(storage_path('app'));
        File::put($flag, 'demo seeded at '.Carbon::now()->toDateTimeString().PHP_EOL);

        $this->command?->info('DemoTransactionSeeder: 15 penjualan, 8 tiket, 3 pembelian, 2 booking, kas/expense/debt demo dibuat.');
    }
}
