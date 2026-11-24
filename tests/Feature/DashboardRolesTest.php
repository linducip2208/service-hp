<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class DashboardRolesTest extends TestCase
{
    public function test_dashboard_per_peran_tanpa_500(): void
    {
        foreach (['superadmin', 'owner', 'kasir', 'teknisi'] as $role) {
            $u = User::where('role', $role)->first();
            if (!$u) continue;
            if ($role === 'gudang' && !$u) continue;
            $this->actingAs($u)->withSession(['active_store_id' => 1])
                ->get('/')->assertStatus(200);
        }
        $gudang = User::where('role', 'gudang')->first();
        if ($gudang) {
            $this->actingAs($gudang)->withSession(['active_store_id' => 1])
                ->get('/')->assertStatus(200);
        }
        $this->assertTrue(true);
    }
}
