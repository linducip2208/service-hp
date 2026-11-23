<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\User;
use Tests\TestCase;

class GiftNoteTest extends TestCase
{
    public function test_ucapan_dan_sig_tidak_bocor(): void
    {
        $owner = User::where('role', 'owner')->firstOrFail();
        $me = $this->actingAs($owner)->withSession(['active_store_id' => 1]);
        $sale = Sale::orderByDesc('id')->firstOrFail();
        $origNote = $sale->note;
        $sale->note = '[UCAPAN] Selamat ulang tahun [SIG:signatures/ttd-uji.png]';
        $sale->save();

        $gift = $me->get('/pos/print/' . $sale->id . '?gift=1')->assertStatus(200)->getContent();
        $this->assertStringContainsString('Selamat ulang tahun', $gift);
        $this->assertStringNotContainsString('SIG:', $gift);
        $this->assertStringNotContainsString('signatures/ttd-uji.png', $gift);

        $normal = $me->get('/pos/print/' . $sale->id)->assertStatus(200)->getContent();
        $this->assertStringNotContainsString('SIG:', $normal);

        $sale->note = $origNote;
        $sale->save();
    }
}
