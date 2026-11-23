<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class WorkflowGatesTest extends TestCase
{
    private function owner()
    {
        return $this->actingAs(User::where('role', 'owner')->firstOrFail())
            ->withSession(['active_store_id' => 1]);
    }

    private function kasir()
    {
        return $this->actingAs(User::where('role', 'kasir')->firstOrFail())
            ->withSession(['active_store_id' => 1]);
    }

    private function makeStockedProduct(string $tag): Product
    {
        $this->owner()->post('/products', [
            'name' => 'Gate Uji ' . $tag, 'type' => 'aksesoris',
            'purchase_price' => 50000, 'selling_price' => 80000,
            'min_stock' => 1, 'initial_stock' => 5,
        ])->assertStatus(302);
        return Product::where('name', 'Gate Uji ' . $tag)->firstOrFail();
    }

    public function test_kasir_ditolak_jual_di_bawah_modal_owner_boleh(): void
    {
        $p = $this->makeStockedProduct('MC' . time());
        $items = [['product_id' => $p->id, 'product_item_id' => null, 'qty' => 1, 'price' => 10000, 'name' => $p->name, 'max' => 5]];
        $payload = [
            'items_json' => json_encode($items), 'payments_json' => '',
            'discount' => 0, 'trade_in_value' => 0, 'installment_tenor' => 0,
            'payment_method' => 'tunai', 'paid_amount' => 10000,
        ];
        $this->kasir()->post('/pos', $payload)->assertStatus(422);
        // owner dengan payload sama lolos gate (bukti gate berbasis role)
        $this->owner()->post('/pos', $payload)->assertStatus(302);
        $sale = Sale::orderByDesc('id')->first();
        $this->owner()->delete('/sales/' . $sale->id)->assertStatus(302);
        $p->update(['is_active' => false]);
    }

    public function test_kasir_ditolak_diskon_manual_lebih_30_persen(): void
    {
        $p = $this->makeStockedProduct('DC' . time());
        $items = [['product_id' => $p->id, 'product_item_id' => null, 'qty' => 1, 'price' => 80000, 'name' => $p->name, 'max' => 5]];
        $this->kasir()->post('/pos', [
            'items_json' => json_encode($items), 'payments_json' => '',
            'discount' => 50000, 'trade_in_value' => 0, 'installment_tenor' => 0,
            'payment_method' => 'tunai', 'paid_amount' => 30000,
        ])->assertStatus(422);
        $p->update(['is_active' => false]);
    }

    public function test_pengeluaran_besar_wajib_bukti_dan_kunci_periode_menolak_backdate(): void
    {
        $me = $this->owner();
        $acc = \App\Models\CashAccount::where('store_id', 1)->firstOrFail();
        $base = ['cash_account_id' => $acc->id, 'category' => 'Operasional', 'expense_date' => now()->toDateString()];

        // >Rp1jt tanpa foto bukti -> validasi menolak
        $me->post('/expenses', $base + ['amount' => 2000000, 'description' => 'Uji gate bukti'])
            ->assertSessionHasErrors('bukti');
        $this->assertEquals(0, \App\Models\Expense::where('description', 'Uji gate bukti')->count());

        // kasir catat >Rp10jt -> 403 walau bukti ada (pakai nominal kecil utk lock test)
        $this->kasir()->post('/expenses', $base + [
            'amount' => 15000000, 'description' => 'Uji gate kasir',
            'bukti' => \Illuminate\Http\UploadedFile::fake()->image('bukti.jpg'),
        ])->assertStatus(403);

        // kunci periode hari ini -> backdate kemarin ditolak 422
        // (actingAs menempel di $this: wajib login ulang sebagai owner)
        $me = $this->actingAs(User::where('role', 'owner')->firstOrFail())
            ->withSession(['active_store_id' => 1]);
        $me->post('/cash/closing/lock', ['lock_date' => now()->toDateString()])->assertStatus(302);
        // NB: pakai array_merge (operator + mempertahankan kiri!)
        $tag = 'Uji backdate ' . time();
        \App\Models\Expense::where('description', 'like', 'Uji backdate%')->delete();
        $me->post('/expenses', array_merge($base, [
            'amount' => 50000, 'description' => $tag, 'expense_date' => now()->subDay()->toDateString(),
        ]))->assertStatus(422);
        $this->assertEquals(0, \App\Models\Expense::where('description', $tag)->count());

        // tanggal hari ini tetap lolos, lalu bersih-bersih via destroy (kembalikan kas)
        $me->post('/expenses', array_merge($base, ['amount' => 50000, 'description' => $tag]))
            ->assertStatus(302);
        $exp = \App\Models\Expense::where('description', $tag)->firstOrFail();
        $me->delete('/expenses/' . $exp->id)->assertStatus(302);
        $this->assertNull(\App\Models\Expense::find($exp->id));
        \App\Models\Expense::where('description', 'Uji backdate')->delete();
        $me->delete('/cash/closing/lock')->assertStatus(302);
    }

    public function test_retur_besar_ditolak_untuk_kasir(): void
    {
        $p = $this->makeStockedProduct('RT' . time());
        $items = [['product_id' => $p->id, 'product_item_id' => null, 'qty' => 1, 'price' => 80000, 'name' => $p->name, 'max' => 5]];
        $this->kasir()->post('/pos', [
            'items_json' => json_encode($items), 'payments_json' => '',
            'discount' => 0, 'trade_in_value' => 0, 'installment_tenor' => 0,
            'payment_method' => 'tunai', 'paid_amount' => 80000,
        ])->assertStatus(302);
        $sale = Sale::orderByDesc('id')->first();
        // retur Rp3jt (>Rp2jt) oleh kasir tanpa bukti -> 422
        $this->kasir()->post('/returns', [
            'sale_id' => $sale->id, 'amount' => 3000000, 'type' => 'refund', 'reason' => 'Uji gate retur',
        ])->assertStatus(422);
        $this->assertEquals(0, \App\Models\SaleReturn::where('sale_id', $sale->id)->count());
        $this->owner()->delete('/sales/' . $sale->id)->assertStatus(302);
        $p->update(['is_active' => false]);
    }

    public function test_profit_recalc_kering_tidak_menulis(): void
    {
        $before = \App\Models\SaleItem::where('profit', 0)->orWhereNull('profit')->count();
        $code = Artisan::call('profit:recalc', ['--dry-run' => true]);
        $this->assertEquals(0, $code);
        $this->assertEquals($before, \App\Models\SaleItem::where('profit', 0)->orWhereNull('profit')->count());
    }
}
