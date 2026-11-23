<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Tests\TestCase;

class PosE2ETest extends TestCase
{
    public function test_pos_tunai_sampai_struk(): void
    {
        $owner = User::where('role', 'owner')->firstOrFail();
        $me = $this->actingAs($owner)->withSession(['active_store_id' => 1]);

        $tag = 'E2E' . time();
        $me->post('/products', [
            'name' => 'Kabel Uji ' . $tag, 'type' => 'aksesoris',
            'purchase_price' => 20000, 'selling_price' => 35000,
            'min_stock' => 1, 'initial_stock' => 10,
        ])->assertStatus(302);
        $p = Product::where('name', 'Kabel Uji ' . $tag)->firstOrFail();

        $items = [['product_id' => $p->id, 'product_item_id' => null, 'qty' => 2, 'price' => 35000, 'name' => $p->name, 'max' => 10]];
        $r = $me->post('/pos', [
            'items_json' => json_encode($items),
            'payments_json' => '',
            'customer_id' => '',
            'discount' => 0,
            'promo_code' => '',
            'trade_in_value' => 0,
            'installment_tenor' => 0,
            'payment_method' => 'tunai',
            'paid_amount' => 70000,
        ]);
        $r->assertStatus(302);
        $sale = Sale::orderByDesc('id')->first();
        $this->assertEquals(70000, (float) $sale->total);

        // struk bisa dibuka (bug $isGift kemarin)
        $me->get('/pos/print/' . $sale->id)->assertStatus(200);
        $me->get('/pos/print/' . $sale->id . '?gift=1')->assertStatus(200);
        $me->get('/sales/' . $sale->id)->assertStatus(200);
        $me->get('/sales/' . $sale->id . '/invoice')->assertStatus(200);

        // stok berkurang 10 -> 8
        $this->assertEquals(8, (int) \App\Models\ProductStock::where('product_id', $p->id)->where('store_id', 1)->sum('qty'));

        // bersih-bersih total: hapus sale (kembalikan stok), lalu hapus produk uji
        // via model (riwayat penjualan sudah dihapus di atas; cascade bersihkan stok/mutasi)
        $me->delete('/sales/' . $sale->id)->assertStatus(302);
        $this->assertEquals(10, (int) \App\Models\ProductStock::where('product_id', $p->id)->where('store_id', 1)->sum('qty'));
        $p->delete();
        $this->assertNull(\App\Models\Product::find($p->id));
    }
}
