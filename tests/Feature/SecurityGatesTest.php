<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as Base;
use Tests\TestCase;

class SecurityGatesTest extends TestCase
{
    private function user(string $role): User
    {
        $u = User::where('role', $role)->first();
        $this->assertNotNull($u, "user role $role tidak ada");
        return $u;
    }

    public function test_health_hanya_owner_superadmin(): void
    {
        $this->actingAs($this->user('kasir'))->get('/health')->assertStatus(403);
        $this->actingAs($this->user('teknisi'))->get('/health')->assertStatus(403);
        $this->actingAs($this->user('owner'))->get('/health')->assertStatus(200);
        $this->actingAs($this->user('superadmin'))->get('/health')->assertStatus(200);
    }

    public function test_settings_dan_stores_terkunci(): void
    {
        $this->actingAs($this->user('kasir'))->get('/settings/wa')->assertStatus(403);
        $this->actingAs($this->user('kasir'))->get('/stores')->assertStatus(403);
        $this->actingAs($this->user('kasir'))->get('/stores/1')->assertStatus(403);
        $this->actingAs($this->user('kasir'))->get('/users')->assertStatus(403);
        $this->actingAs($this->user('kasir'))->get('/settings/backup')->assertStatus(403);
        $this->actingAs($this->user('kasir'))->get('/activity/log?date='.now()->format('Y-m-d'))->assertStatus(403);
        $this->actingAs($this->user('kasir'))->get('/reminders')->assertStatus(403);
        $this->actingAs($this->user('teknisi'))->get('/stocks/opname')->assertStatus(403);
        // gudang boleh stok
        $gudang = User::where('role', 'gudang')->first();
        if ($gudang) {
            $this->actingAs($gudang)->get('/stocks/opname')->assertStatus(200);
        }
    }

    public function test_bayar_komisi_ditolak_teknisi(): void
    {
        $tech = $this->user('teknisi');
        $this->actingAs($tech)->post('/technicians/'.$tech->id.'/pay', [
            'cash_account_id' => 1,
        ])->assertStatus(403);
    }

    public function test_guest_dialihkan_ke_login(): void
    {
        $this->get('/health')->assertRedirect('/login');
        $this->get('/pos')->assertRedirect('/login');
        $this->get('/cash')->assertRedirect('/login');
    }

    public function test_throttle_login_ada(): void
    {
        $routes = \Illuminate\Support\Facades\Route::getRoutes();
        $r = $routes->getByName('login.post');
        $this->assertNotNull($r);
        $this->assertContains('throttle:10,1', $r->gatherMiddleware());
    }
}
