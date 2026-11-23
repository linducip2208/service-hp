<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class BackupFileTest extends TestCase
{
    public function test_backup_verify_berhasil(): void
    {
        $me = $this->actingAs(User::where('role', 'owner')->firstOrFail())
            ->withSession(['active_store_id' => 1]);
        $files = glob(storage_path('app/backups/auto-*'));
        $this->assertNotEmpty($files, 'harus ada file backup auto-*');
        // verify: redirect kembali + flash hasil (baca: SEHAT/RUSAK)
        $res = $me->get('/settings/backup/' . basename($files[0]) . '/verify');
        $res->assertStatus(302);
        $res->assertSessionHasNoErrors();
    }
}
