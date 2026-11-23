<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class InterconnectTest extends TestCase
{
    public function test_semua_route_get_tanpa_500(): void
    {
        $me = $this->actingAs(User::where('role', 'owner')->firstOrFail())
            ->withSession(['active_store_id' => 1]);

        $fx = $this->makeFixtures();

        $modelFor = [
            'sale' => \App\Models\Sale::class,
            'product' => \App\Models\Product::class,
            'unit' => \App\Models\ProductItem::class,
            'service' => \App\Models\ServiceTicket::class,
            'booking' => \App\Models\Booking::class,
            'warranty' => \App\Models\Warranty::class,
            'customer' => \App\Models\Customer::class,
            'supplier' => \App\Models\Supplier::class,
            'brand' => \App\Models\Brand::class,
            'category' => \App\Models\Category::class,
            'store' => \App\Models\Store::class,
            'user' => \App\Models\User::class,
            'payment' => \App\Models\SalePayment::class,
            'consignment' => \App\Models\Consignment::class,
            'debt' => \App\Models\Debt::class,
            'purchase' => \App\Models\Purchase::class,
            'promo' => \App\Models\Promo::class,
            'account' => \App\Models\CashAccount::class,
            'transaction' => \App\Models\CashTransaction::class,
            'sparepart' => \App\Models\ServiceSparepart::class,
            'technician' => \App\Models\User::class,
            'expense' => \App\Models\Expense::class,
        ];

        $fail = []; $skipped = []; $ok = 0;
        foreach (Route::getRoutes() as $r) {
            if (!in_array('GET', $r->methods())) continue;
            $uri = $r->uri();
            if (str_starts_with($uri, 'sanctum/') || str_starts_with($uri, '_ignition')) continue;
            // ganti {param} dengan id asli
            $params = [];
            $can = true;
            $url = preg_replace_callback('/\{(\w+)\??\}/', function ($m) use (&$params, &$can, $modelFor) {
                $name = rtrim($m[1], '?');
                if ($name === 'id') { $params[$name] = 1; return '1'; }
                if (!isset($modelFor[$name])) { $can = false; return $m[0]; }
                $rec = $modelFor[$name]::first();
                if (!$rec) { $can = false; return $m[0]; }
                $params[$name] = $rec->getKey();
                return (string) $rec->getKey();
            }, '/' . ltrim($uri, '/'));
            if (!$can) { $skipped[] = $uri; continue; }
            // file download: hanya cek header tanpa isi
            if (str_contains($uri, '{file}')) { $skipped[] = $uri . ' (file-download)'; continue; }
            try {
                $res = $me->get($url);
                // TestResponse teruskan ke respons Symfony: getStatusCode() selalu ada.
                $code = $res instanceof \Illuminate\Testing\TestResponse
                    ? $res->baseResponse->getStatusCode()
                    : $res->getStatusCode();
            } catch (\Throwable $e) {
                $fail[] = "$url => EXC " . get_class($e) . ': ' . substr($e->getMessage(), 0, 120);
                continue;
            }
            if ($code >= 500) $fail[] = "$url => $code";
            else $ok++;
        }
        fwrite(STDERR, "\nOK=$ok SKIP=" . count($skipped) . "\nSKIPPED: " . implode(', ', array_slice($skipped, 0, 20)) . "\n");
        $this->cleanupFixtures($fx);
        $this->assertSame([], $fail, "Route 500: \n" . implode("\n", $fail));
    }

    /** Fixture minimal agar route berparameter punya data. Gagal = skip rute tsb. */
    private function makeFixtures(): array
    {
        $fx = [];
        try {
            $cust = \App\Models\Customer::create(['store_id' => 1, 'name' => 'Uji Koneksi']);
            $fx['customer'] = $cust->id;
            $svc = \App\Models\ServiceTicket::create([
                'store_id' => 1, 'customer_id' => $cust->id,
                'ticket_no' => 'TEST-' . time(), 'complaint' => 'Uji koneksi',
                'status' => 'diterima',
            ]);
            $fx['service'] = $svc->id;
        } catch (\Throwable $e) { fwrite(STDERR, "\nSKIP-FIX service: " . substr($e->getMessage(), 0, 120) . "\n"); }
        try {
            $w = \App\Models\Warranty::create(['status' => 'aktif', 'type' => 'toko']);
            $fx['warranty'] = $w->id;
        } catch (\Throwable $e) { fwrite(STDERR, "\nSKIP-FIX warranty: " . substr($e->getMessage(), 0, 120) . "\n"); }
        try {
            $d = \App\Models\Debt::create(['store_id' => 1, 'type' => 'piutang', 'party_name' => 'Uji Koneksi', 'total' => 10000, 'paid' => 0, 'remaining' => 10000, 'status' => 'belum']);
            $fx['debt'] = $d->id;
        } catch (\Throwable $e) { fwrite(STDERR, "\nSKIP-FIX debt: " . substr($e->getMessage(), 0, 120) . "\n"); }
        try {
            $p = \App\Models\Purchase::create(['store_id' => 1, 'invoice_no' => 'TEST-PB-' . time(), 'total' => 10000, 'paid' => 0, 'status' => 'belum']);
            $fx['purchase'] = $p->id;
        } catch (\Throwable $e) { fwrite(STDERR, "\nSKIP-FIX purchase: " . substr($e->getMessage(), 0, 120) . "\n"); }
        try {
            $prod = \App\Models\Product::create(['sku' => 'TEST-U-' . time(), 'name' => 'Unit Uji Koneksi', 'type' => 'hp_bekas', 'purchase_price' => 100, 'selling_price' => 200, 'is_serialized' => true]);
            $u = \App\Models\ProductItem::create(['product_id' => $prod->id, 'store_id' => 1, 'imei' => 'TESTIMEI' . time(), 'status' => 'tersedia']);
            $fx['unit'] = $u->id;
            $fx['product_tmp'] = $prod->id;
        } catch (\Throwable $e) { fwrite(STDERR, "\nSKIP-FIX unit: " . substr($e->getMessage(), 0, 120) . "\n"); }
        return $fx;
    }

    private function cleanupFixtures(array $fx): void
    {
        try {
            if (!empty($fx['service'])) \App\Models\ServiceTicket::whereKey($fx['service'])->delete();
            if (!empty($fx['debt'])) \App\Models\Debt::whereKey($fx['debt'])->delete();
            if (!empty($fx['purchase'])) \App\Models\Purchase::whereKey($fx['purchase'])->delete();
            if (!empty($fx['warranty'])) \App\Models\Warranty::whereKey($fx['warranty'])->delete();
            if (!empty($fx['unit'])) \App\Models\ProductItem::whereKey($fx['unit'])->delete();
            if (!empty($fx['product_tmp'])) \App\Models\Product::whereKey($fx['product_tmp'])->delete();
            if (!empty($fx['customer'])) \App\Models\Customer::whereKey($fx['customer'])->delete();
        } catch (\Throwable $e) { fwrite(STDERR, "\nCLEANUP: " . substr($e->getMessage(), 0, 120) . "\n"); }
    }
}
