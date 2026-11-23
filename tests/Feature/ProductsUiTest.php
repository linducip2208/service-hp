<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class ProductsUiTest extends TestCase
{
    public function test_products_index_redesign_markers(): void
    {
        $me = $this->actingAs(User::where('role', 'owner')->firstOrFail())
            ->withSession(['active_store_id' => 1]);
        $html = $me->get('/products')->assertStatus(200)->getContent();
        // header baru: primer + dropdown alat
        $this->assertStringContainsString('Alat & Laporan', $html);
        $this->assertStringContainsString('id="printLabelsBtn"', $html);
        $this->assertStringContainsString('id="printCatalogBtn"', $html);
        // import collapsible + filter kompak
        $this->assertStringContainsString('<details class="card mb-3">', $html);
        // thumbnail kolom: placeholder bila tak ada foto, img bila ada
        $this->assertStringContainsString('ti-package', $html);
        $prod = \App\Models\Product::first();
        $origPhoto = $prod->photo;
        @mkdir(storage_path('app/public/products'), 0777, true);
        file_put_contents(storage_path('app/public/products/uji-thumb.jpg'), 'x');
        $prod->photo = 'products/uji-thumb.jpg';
        $prod->save();
        $html2 = $me->get('/products')->assertStatus(200)->getContent();
        $this->assertStringContainsString('object-fit:cover', $html2);
        $this->assertStringContainsString('uji-thumb.jpg', $html2);
        $prod->photo = $origPhoto;
        $prod->save();
        @unlink(storage_path('app/public/products/uji-thumb.jpg'));
        // badge nonaktif netral: nonaktifkan satu produk sementara
        $wasActive = (int) $prod->is_active;
        $prod->is_active = false;
        $prod->save();
        $html3 = $me->get('/products')->assertStatus(200)->getContent();
        $this->assertStringContainsString('bg-secondary-lt">nonaktif', $html3);
        $prod->is_active = $wasActive;
        $prod->save();
        $this->assertStringContainsString('dropdown-toggle', $html);
        // tidak ada lagi banner tren master bertele-tele
        $this->assertStringNotContainsString('Pertumbuhan Master', $html);
        // sidebar baru ter-render
        $this->assertStringContainsString('id="side-nav"', $html);
        $this->assertStringContainsString('id="menu-search"', $html);
    }

    public function test_no_test_artifacts_left(): void
    {
        // Janitor: hapus sisa produk uji TANPA riwayat (aman via FK cascade).
        // Test lain sudah bersih-bersih sendiri; ini jaring pengaman terakhir.
        foreach (\App\Models\Product::where('name', 'like', 'Gate Uji%')
            ->orWhere('name', 'like', 'Kabel Uji%')->get() as $p) {
            if (\App\Models\SaleItem::where('product_id', $p->id)->exists()) continue;
            if (\App\Models\PurchaseItem::where('product_id', $p->id)->exists()) continue;
            if (\App\Models\ServiceSparepart::where('product_id', $p->id)->exists()) continue;
            $p->delete();
        }
        $this->assertEquals(0, \App\Models\Product::where('name', 'like', 'Gate Uji%')->count());
        $this->assertEquals(0, \App\Models\Product::where('name', 'like', 'Kabel Uji%')
            ->whereNotIn('id', \App\Models\SaleItem::select('product_id'))->count());
    }
}
