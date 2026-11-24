<?php

namespace Tests\Feature;

use App\Models\ServiceTicket;
use App\Models\User;
use Tests\TestCase;

class KanbanDevicesTest extends TestCase
{
    private function owner()
    {
        return $this->actingAs(User::where('role', 'owner')->firstOrFail())
            ->withSession(['active_store_id' => 1]);
    }

    public function test_kanban_render_dan_pindah(): void
    {
        $me = $this->owner();
        $me->get('/services/kanban')->assertStatus(200);

        $cust0 = \App\Models\Customer::create(['store_id' => 1, 'name' => 'Uji Kanban', 'phone' => '0812999111']);
        $t = ServiceTicket::create([
            'store_id' => 1, 'customer_id' => $cust0->id, 'ticket_no' => 'TESTKB' . time(),
            'complaint' => 'Uji kanban', 'status' => 'diterima',
        ]);
        $html = $me->get('/services/kanban')->assertStatus(200)->getContent();
        $this->assertStringContainsString($t->ticket_no, $html);

        // pindah valid diterima -> diagnosa via tombol kanban (endpoint sama)
        $me->post('/services/' . $t->id . '/status', ['to_status' => 'diagnosa'])->assertStatus(302);
        $this->assertEquals('diagnosa', $t->fresh()->status);
        $this->assertStringContainsString($t->ticket_no, $me->get('/services/kanban')->getContent());

        $t->delete();
        \App\Models\ServiceLog::where('service_ticket_id', $t->id)->delete();
        $cust0->delete();
    }

    public function test_customer_devices_muncul(): void
    {
        $me = $this->owner();
        $cust = \App\Models\Customer::create(['store_id' => 1, 'name' => 'Uji Perangkat', 'phone' => '0812999000']);
        $t = ServiceTicket::create([
            'store_id' => 1, 'customer_id' => $cust->id, 'ticket_no' => 'TESTDV' . time(),
            'product_brand' => 'Oppo', 'product_type' => 'A58', 'imei' => 'TESTIMEIDEV' . time(),
            'complaint' => 'Uji perangkat', 'status' => 'diterima',
        ]);
        $html = $me->get('/customers/' . $cust->id)->assertStatus(200)->getContent();
        $this->assertStringContainsString('Perangkat', $html);
        $this->assertStringContainsString(substr($t->imei, 0, 12), $html);

        $t->delete();
        \App\Models\ServiceLog::where('service_ticket_id', $t->id)->delete();
        $cust->delete();
    }
}
