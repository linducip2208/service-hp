<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\ServiceTicket;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class InterconnectWriteTest extends TestCase
{
    private function owner()
    {
        return $this->actingAs(User::where('role', 'owner')->firstOrFail())
            ->withSession(['active_store_id' => 1]);
    }

    public function test_service_lifecycle_terhubung(): void
    {
        $me = $this->owner();
        $tag = 'UJI' . time();

        // 1. terima HP (wajib foto kondisi)
        $me->post('/services', [
            'customer_name' => 'Pelanggan ' . $tag, 'customer_phone' => '0812000111',
            'product_brand' => 'Samsung', 'product_type' => 'A15',
            'complaint' => 'Mati total ' . $tag,
            'photos' => [UploadedFile::fake()->image('depan.jpg')],
        ])->assertStatus(302);
        $t = ServiceTicket::where('complaint', 'Mati total ' . $tag)->firstOrFail();
        $this->assertEquals('diterima', $t->status);

        // 2. lompat ilegal diterima -> diserahkan ditolak (pesan error, bukan 500)
        $me->post('/services/' . $t->id . '/status', ['to_status' => 'diserahkan'])
            ->assertSessionHas('error');
        $this->assertEquals('diterima', $t->fresh()->status);

        // 3. transisi valid diterima -> diagnosa + estimasi
        $me->post('/services/' . $t->id . '/status', [
            'to_status' => 'diagnosa', 'diagnosis' => 'IC power', 'estimate_cost' => 250000,
        ])->assertStatus(302);
        $this->assertEquals('diagnosa', $t->fresh()->status);

        // 4. hapus tiket via controller (koreksi total, tanpa retur/kas terkait)
        $me->delete('/services/' . $t->id)->assertStatus(302);
        $this->assertNull(ServiceTicket::find($t->id));
        $this->assertEquals(0, \App\Models\ServiceLog::where('service_ticket_id', $t->id)->count());
    }

    public function test_booking_publik_sampai_tiket_otomatis(): void
    {
        $phone = '0812' . substr((string) time(), -8);
        // 1. publik booking tanpa login
        $this->post('/booking', [
            'store_id' => 1, 'customer_name' => 'Tamu Uji', 'customer_phone' => $phone,
            'device' => 'Xiaomi 13', 'complaint' => 'Layar retak uji',
            'schedule_date' => now()->addDay()->format('Y-m-d H:i:s'),
        ])->assertStatus(302);
        $b = Booking::where('customer_phone', $phone)->firstOrFail();
        $this->assertEquals('menunggu', $b->status);

        // 2. owner konfirmasi -> customer + tiket otomatis
        $me = $this->owner();
        $me->put('/bookings/' . $b->id, ['status' => 'dikonfirmasi'])->assertStatus(302);
        $b->refresh();
        $this->assertEquals('dikonfirmasi', $b->status);
        $this->assertNotNull($b->ticket_id);
        $ticketId = $b->ticket_id;
        $this->assertTrue(Customer::where('store_id', 1)->where('phone', $phone)->exists());

        // 3. bersih-bersih: tiket (model, cascade log) -> booking -> customer
        ServiceTicket::whereKey($ticketId)->delete();
        $me->delete('/bookings/' . $b->id)->assertStatus(302);
        Customer::where('store_id', 1)->where('phone', $phone)->delete();
        $this->assertNull(Booking::find($b->id));
    }

    public function test_halaman_wa_tetap_200_saat_ada_log(): void
    {
        $me = $this->owner();
        \App\Models\WaLog::create([
            'store_id' => 1, 'phone' => '628120000001', 'message' => 'Uji tampil log',
            'type' => 'test', 'status' => 'antri',
        ]);
        $me->get('/settings/wa')->assertStatus(200);
        \App\Models\WaLog::where('message', 'Uji tampil log')->delete();
    }

    public function test_kas_transfer_pulang_pergi_balance(): void
    {
        $me = $this->owner();
        $acc = \App\Models\CashAccount::where('store_id', 1)->orderBy('id')->get();
        $this->assertTrue($acc->count() >= 1);
        if ($acc->count() < 2) {
            $me->post('/cash/accounts', ['name' => 'Kas Uji Transfer'])->assertStatus(302);
            $acc = \App\Models\CashAccount::where('store_id', 1)->orderBy('id')->get();
        }
        [$a, $b] = [$acc[0], $acc[1]];
        $ba0 = (float) $a->fresh()->balance;
        $bb0 = (float) $b->fresh()->balance;

        $me->post('/cash/transfer', [
            'from_account_id' => $a->id, 'to_account_id' => $b->id, 'amount' => 1000,
        ])->assertStatus(302);
        $this->assertEquals($ba0 - 1000, (float) $a->fresh()->balance);
        $this->assertEquals($bb0 + 1000, (float) $b->fresh()->balance);

        // kembalikan (saldo kembali seperti semula; riwayat transfer tetap tercatat)
        $me->post('/cash/transfer', [
            'from_account_id' => $b->id, 'to_account_id' => $a->id, 'amount' => 1000,
        ])->assertStatus(302);
        $this->assertEquals($ba0, (float) $a->fresh()->balance);
        $this->assertEquals($bb0, (float) $b->fresh()->balance);
    }
}
