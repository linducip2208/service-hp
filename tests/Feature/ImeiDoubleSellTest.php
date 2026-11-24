<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductItem;
use App\Models\ProductStock;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warranty;
use Tests\TestCase;

class ImeiDoubleSellTest extends TestCase
{
    public function test_unit_tidak_bisa_terjual_dua_kali_dan_garansi_otomatis(): void
    {
        $me = $this->actingAs(User::where('role', 'owner')->firstOrFail())
            ->withSession(['active_store_id' => 1]);

        $tag = 'IMEI' . time();
        $me->post('/products', [
            'name' => 'HP Uji ' . $tag, 'type' => 'hp_baru',
            'purchase_price' => 1000000, 'selling_price' => 1500000,
            'min_stock' => 1, 'is_serialized' => 1,
        ])->assertStatus(302);
        $p = Product::where('name', 'HP Uji ' . $tag)->firstOrFail();
        $unit = ProductItem::create([
            'product_id' => $p->id, 'store_id' => 1,
            'imei' => 'TEST' . time(), 'status' => 'tersedia',
            'purchase_price' => 1000000, 'selling_price' => 1500000,
        ]);
        ProductStock::create(['product_id' => $p->id, 'store_id' => 1, 'qty' => 1]);

        $payload = function () use ($p, $unit) {
            return [
                'items_json' => json_encode([['product_id' => $p->id, 'product_item_id' => $unit->id, 'qty' => 1, 'price' => 1500000, 'name' => $p->name, 'max' => 1]]),
                'payments_json' => '', 'discount' => 0, 'trade_in_value' => 0,
                'installment_tenor' => 0, 'payment_method' => 'tunai', 'paid_amount' => 1500000,
            ];
        };

        // penjualan pertama lolos
        $me->post('/pos', $payload())->assertStatus(302);
        $sale = Sale::orderByDesc('id')->first();
        $this->assertEquals('terjual', $unit->fresh()->status);

        // garansi toko otomatis terbit untuk unit terjual
        $this->assertTrue(
            Warranty::where('product_item_id', $unit->id)->where('ref_type', 'sale')->where('ref_id', $sale->id)->exists()
        );

        // penjualan kedua unit yang sama DITOLAK (tidak tersedia)
        $me->post('/pos', $payload())->assertStatus(422);
        $this->assertEquals(1, Sale::where('id', '>=', $sale->id)->count());

        // bersih-bersih: hapus sale (unit kembali tersedia + stok kembali), lalu unit + produk
        $me->delete('/sales/' . $sale->id)->assertStatus(302);
        $this->assertEquals('tersedia', $unit->fresh()->status);
        Warranty::where('product_item_id', $unit->id)->delete();
        $unit->delete();
        $p->delete();
        $this->assertNull(Product::find($p->id));
    }
}
