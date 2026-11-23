<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class CrudSmokeTest extends TestCase
{
    private function owner(): User
    {
        return User::where('role', 'owner')->firstOrFail();
    }

    public function test_semua_halaman_index_tanpa_500(): void
    {
        $urls = [
            '/', '/pos', '/sales', '/sales/closing', '/returns', '/tradeins',
            '/secondhand', '/consignments', '/products', '/products/bulk',
            '/products/reorder', '/units', '/stocks/valuation', '/stocks/deadstock',
            '/stocks/opname', '/stocks/mutations', '/services', '/services/report',
            '/warranties', '/bookings', '/bookings/calendar', '/technicians',
            '/technicians/report', '/cash', '/cash/flow', '/cash/closing',
            '/expenses', '/debts', '/reports/profit', '/purchases',
            '/stores', '/stores/monitor', '/customers', '/suppliers',
            '/brands', '/categories', '/promos', '/reminders', '/users',
            '/settings/wa', '/settings/printers', '/settings/backup',
            '/settings/storage', '/health', '/setup', '/activity',
            '/profile', '/help', '/search?q=s', '/notifications/count',
        ];
        $fail = [];
        foreach ($urls as $u) {
            $r = $this->actingAs($this->owner())->get($u);
            if ($r->status() >= 500) $fail[] = "$u => {$r->status()}";
        }
        $this->assertSame([], $fail, 'Halaman 500: ' . implode(', ', $fail));
    }

    public function test_crud_brand(): void
    {
        $me = $this->actingAs($this->owner());
        $code = 'TMP' . time();
        $r = $me->post('/brands', ['name' => 'Brand Uji ' . $code]);
        $r->assertStatus(302);
        $b = \App\Models\Brand::where('name', 'Brand Uji ' . $code)->firstOrFail();
        $me->put('/brands/' . $b->id, ['name' => 'Brand Uji ' . $code . ' X'])->assertStatus(302);
        $me->delete('/brands/' . $b->id)->assertStatus(302);
        $this->assertNull(\App\Models\Brand::find($b->id));
    }

    public function test_crud_category(): void
    {
        $me = $this->actingAs($this->owner());
        $code = 'TMP' . time();
        $me->post('/categories', ['name' => 'Kat Uji ' . $code, 'type' => 'hp'])->assertStatus(302);
        $c = \App\Models\Category::where('name', 'Kat Uji ' . $code)->firstOrFail();
        $me->put('/categories/' . $c->id, ['name' => 'Kat Uji ' . $code . ' X', 'type' => 'hp'])->assertStatus(302);
        $me->delete('/categories/' . $c->id)->assertStatus(302);
        $this->assertNull(\App\Models\Category::find($c->id));
    }

    public function test_crud_supplier(): void
    {
        $me = $this->actingAs($this->owner());
        $code = 'TMP' . time();
        $me->post('/suppliers', ['code' => 'SUPTMP' . $code, 'name' => 'Sup Uji ' . $code, 'phone' => '0812000000'])->assertStatus(302);
        $s = \App\Models\Supplier::where('name', 'Sup Uji ' . $code)->firstOrFail();
        $me->put('/suppliers/' . $s->id, ['code' => 'SUPTMP' . $code, 'name' => 'Sup Uji ' . $code . ' X', 'phone' => '0812000000'])->assertStatus(302);
        $me->delete('/suppliers/' . $s->id)->assertStatus(302);
        $this->assertNull(\App\Models\Supplier::find($s->id));
    }

    public function test_laporan_tidak_500(): void
    {
        $me = $this->actingAs($this->owner());
        $me->get('/technicians/report?bulan=' . now()->format('Y-m'))->assertStatus(200);
        $me->get('/technicians/report?tahun=' . now()->year)->assertStatus(200);
        $me->get('/services/report')->assertStatus(200);
        $saleId = \App\Models\Sale::first()?->id;
        if ($saleId) $me->get('/pos/print/' . $saleId)->assertStatus(200);
        $this->assertTrue(true);
    }

    public function test_crud_product_minimal(): void
    {
        $me = $this->actingAs($this->owner());
        $sku = 'TMPSKU' . time();
        $me->post('/products', [
            'name' => 'Produk Uji ' . $sku, 'type' => 'aksesoris',
            'purchase_price' => 10000, 'selling_price' => 15000, 'min_stock' => 1,
        ])->assertStatus(302);
        $p = \App\Models\Product::where('name', 'Produk Uji ' . $sku)->firstOrFail();
        $me->put('/products/' . $p->id, [
            'name' => 'Produk Uji ' . $sku . ' X', 'type' => 'aksesoris',
            'purchase_price' => 10000, 'selling_price' => 15000, 'min_stock' => 1,
        ])->assertStatus(302);
        $me->delete('/products/' . $p->id)->assertStatus(302);
        $this->assertNull(\App\Models\Product::find($p->id));
    }
}
