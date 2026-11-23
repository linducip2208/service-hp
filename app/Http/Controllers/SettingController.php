<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SettingController extends Controller
{
    /** Konfigurasi gateway WA + token: hanya superadmin/owner/admin. */
    private function canManage(): bool
    {
        return in_array(auth()->user()->role ?? '', ['superadmin', 'owner', 'admin']);
    }

    public function wa()
    {
        abort_unless($this->canManage(), 403);
        $cfg = $this->cfg();
        $tpls = \App\Services\WaService::templates();
        $logs = collect();
        try { if (\Schema::hasTable('wa_logs')) $logs = \DB::table('wa_logs')->orderByDesc('id')->limit(20)->get(); } catch (\Throwable $e) {}
        return view('settings.wa', compact('cfg', 'logs', 'tpls'));
    }

    public function waSave(Request $request)
    {
        abort_unless($this->canManage(), 403);
        $request->validate([
            'wa_url' => 'nullable|url', 'wa_token' => 'nullable|string',
            'tpl_service_diterima' => 'nullable|string|max:1000',
            'tpl_service_siap' => 'nullable|string|max:1000',
            'tpl_service_diserahkan' => 'nullable|string|max:1000',
            'tpl_booking' => 'nullable|string|max:1000',
            'tpl_hutang' => 'nullable|string|max:1000',
        ]);
        // Pertahankan key existing (terutama TPL_*), timpa hanya yang dikirim form
        $f = storage_path('app/wa.json');
        $old = file_exists($f) ? json_decode(file_get_contents($f), true) : [];
        if (!is_array($old)) $old = [];
        $data = array_merge($old, [
            'WA_GATEWAY_URL' => $request->wa_url,
            'WA_GATEWAY_TOKEN' => $request->wa_token,
            'WA_SENDER' => $request->wa_sender,
            'TPL_SERVICE_DITERIMA' => $request->input('tpl_service_diterima', $old['TPL_SERVICE_DITERIMA'] ?? null),
            'TPL_SERVICE_SIAP' => $request->input('tpl_service_siap', $old['TPL_SERVICE_SIAP'] ?? null),
            'TPL_SERVICE_DISERAHKAN' => $request->input('tpl_service_diserahkan', $old['TPL_SERVICE_DISERAHKAN'] ?? null),
            'TPL_BOOKING' => $request->input('tpl_booking', $old['TPL_BOOKING'] ?? null),
            'TPL_HUTANG' => $request->input('tpl_hutang', $old['TPL_HUTANG'] ?? null),
        ]);
        file_put_contents($f, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        // simpan juga ke .env runtime (opsional)
        return back()->with('success', 'Konfigurasi WhatsApp + template tersimpan. Placeholder: {nama} {tiket} {status} {total} (+ {sisa} {jadwal} {device} {track}).');
    }

    public function waTest(Request $request)
    {
        abort_unless($this->canManage(), 403);
        $request->validate(['phone' => 'required', 'message' => 'required']);
        $res = \App\Services\WaService::send($request->phone, $request->message, 'test');
        return back()->with($res ? 'success' : 'error', $res ? 'Pesan test dikirim.' : 'Gagal. Cek URL/token gateway & lihat storage/logs.');
    }

    /** Kirim ulang satu baris log WA (gagal/antri) via gateway aktif. */
    public function waRetry(int $id)
    {
        abort_unless($this->canManage(), 403);
        try {
            $log = \DB::table('wa_logs')->where('id', $id)->first();
            abort_unless($log, 404, 'Log WA tidak ditemukan.');
            $ok = \App\Services\WaService::send($log->phone, $log->message, $log->type ?? 'retry', $log->store_id);
            // Tandai baris lama agar tidak dikirim ulang dua kali
            try {
                \DB::table('wa_logs')->where('id', $id)->update([
                    'status' => $ok ? 'terkirim' : 'gagal',
                    'response' => substr(($log->response ? $log->response . ' | ' : '') . 'retry:' . now()->format('d/m H:i'), 0, 1000),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $e) {}
            return back()->with($ok ? 'success' : 'error', $ok ? "WA #$id dikirim ulang." : "Retry WA #$id gagal. Cek gateway.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Retry gagal: ' . $e->getMessage());
        }
    }

    public static function cfg(): array
    {
        $f = storage_path('app/wa.json');
        $j = file_exists($f) ? json_decode(file_get_contents($f), true) : [];
        return array_merge([
            'WA_GATEWAY_URL' => env('WA_GATEWAY_URL', ''),
            'WA_GATEWAY_TOKEN' => env('WA_GATEWAY_TOKEN', ''),
            'WA_SENDER' => env('WA_SENDER', 'ServiceHP'),
        ], $j ?: []);
    }
}
