<?php

namespace App\Services;

class WaService
{
    /**
     * Kirim WA via gateway generik (Fonnte/Wablas/dll).
     * Konfigurasi: storage/app/wa.json {WA_GATEWAY_URL, WA_GATEWAY_TOKEN, WA_SENDER, TPL_*}
     * URL dipanggil dengan POST {target|phone, message, ...}. Disesuaikan via .env.
     */
    public static function send(string $phone, string $message, string $type = 'notif', ?int $storeId = null): bool
    {
        $phone = self::normalize($phone);
        $status = 'antri';
        $response = null;
        try {
            $f = storage_path('app/wa.json');
            $cfg = file_exists($f) ? json_decode(file_get_contents($f), true) : [];
            $url = $cfg['WA_GATEWAY_URL'] ?? env('WA_GATEWAY_URL', '');
            $token = $cfg['WA_GATEWAY_TOKEN'] ?? env('WA_GATEWAY_TOKEN', '');

            if ($url) {
                $resp = \Illuminate\Support\Facades\Http::withHeaders(
                    $token ? ['Authorization' => $token] : []
                )->timeout(15)->post($url, [
                    'target' => $phone, 'phone' => $phone,
                    'message' => $message, 'text' => $message,
                ]);
                $response = substr((string) $resp->body(), 0, 1000);
                $status = $resp->successful() ? 'terkirim' : 'gagal';
            } else {
                // Tanpa gateway: catat sebagai antri (mode log) agar alur tetap jalan
                \Illuminate\Support\Facades\Log::info("[WA][$type] $phone: $message");
                $status = 'antri';
            }
        } catch (\Throwable $e) {
            $response = substr($e->getMessage(), 0, 1000);
            $status = 'gagal';
        }

        try {
            if (\Schema::hasTable('wa_logs')) {
                \DB::table('wa_logs')->insert([
                    'store_id' => $storeId ?? session('active_store_id'),
                    'phone' => $phone, 'message' => $message, 'type' => $type,
                    'status' => $status, 'response' => $response,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {}

        return $status !== 'gagal';
    }

    public static function normalize(string $phone): string
    {
        $p = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($p, '0')) $p = '62' . substr($p, 1);
        return $p;
    }

    // ---------- Template notifikasi (TPL_* di storage/app/wa.json) ----------

    /** Template bawaan (fallback bila TPL_* kosong). Placeholder: {nama} {tiket} {status} {total} + {sisa} {jadwal} {device} {track} */
    public static function defaults(): array
    {
        return [
            'service_diterima' => 'Halo {nama}, service Anda tiket *{tiket}* sudah kami terima. Status: *{status}*. Lacak: {track}',
            'service_siap_diambil' => 'Halo {nama}, HP Anda tiket *{tiket}* sudah SIAP DIAMBIL. Total Rp {total}, sisa bayar Rp {sisa}. {track}',
            'service_diserahkan' => 'Halo {nama}, terima kasih! Service tiket *{tiket}* sudah DISERAHKAN. Simpan struk untuk klaim garansi.',
            'booking_konfirmasi' => 'Halo {nama}, pengingat booking service *{tiket}* ({device}) jadwal {jadwal}. Mohon datang tepat waktu. Balas OK untuk konfirmasi.',
            'hutang_jatuh_tempo' => 'Halo {nama}, pengingat tagihan sebesar Rp {total} (jatuh tempo: {jadwal}). Terima kasih.',
        ];
    }

    /** Gabungan template tersimpan + bawaan (tersimpan menang bila non-kosong). */
    public static function templates(): array
    {
        $f = storage_path('app/wa.json');
        $cfg = file_exists($f) ? json_decode(file_get_contents($f), true) : [];
        $out = self::defaults();
        $map = [
            'service_diterima' => 'TPL_SERVICE_DITERIMA',
            'service_siap_diambil' => 'TPL_SERVICE_SIAP',
            'service_diserahkan' => 'TPL_SERVICE_DISERAHKAN',
            'booking_konfirmasi' => 'TPL_BOOKING',
            'hutang_jatuh_tempo' => 'TPL_HUTANG',
        ];
        foreach ($map as $key => $cfgKey) {
            if (!empty($cfg[$cfgKey])) $out[$key] = (string) $cfg[$cfgKey];
        }
        return $out;
    }

    /**
     * Render template per kejadian. $data: ['nama'=>..,'tiket'=>..,'status'=>..,'total'=>..,'sisa'=>..,'jadwal'=>..,'device'=>..,'track'=>..]
     * Placeholder tak dikenal dibiarkan; bila template kosong → '' (pemanggil wajib fallback ke string lama).
     */
    public static function render(string $key, array $data = []): string
    {
        $tpls = self::templates();
        $tpl = trim((string) ($tpls[$key] ?? ''));
        if ($tpl === '') return '';
        foreach ($data as $k => $v) {
            $tpl = str_replace('{' . $k . '}', (string) $v, $tpl);
        }
        return $tpl;
    }

    public static function serviceStatus(string $phone, string $nama, string $tiket, string $status): bool
    {
        // Pakai template per-status bila ada (service_{status}), fallback ke string lama
        $tplKey = 'service_' . $status;
        $msg = self::render($tplKey, [
            'nama' => $nama, 'tiket' => $tiket, 'status' => $status,
            'track' => url('/track'),
        ]);
        if ($msg === '') {
            $msg = "Halo {$nama}, status service tiket *{$tiket}* di ServiceHP: *{$status}*. Cek: " . url('/track');
        }
        return self::send($phone, $msg, 'service_status');
    }
}
