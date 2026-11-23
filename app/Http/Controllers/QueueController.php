<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class QueueController extends Controller
{
    /**
     * Ambil nomor antrean walk-in — publik tanpa auth (seperti /display).
     * Counter harian file-based: storage/app/queue-YYYYMMDD.txt (LOCK_EX).
     * Format: A001, A002, ... Reset otomatis tiap hari (file per tanggal).
     * Tanpa migration / tabel baru.
     */
    public function take(Request $request)
    {
        $storeId = $request->input('store', $request->query('store'));
        $storeId = is_numeric($storeId) ? (int) $storeId : null;

        $date = date('Ymd');
        $path = storage_path('app/queue-' . $date . '.txt');

        // Atomic read-modify-write dengan LOCK_EX (aman dari race 2 pengambil bersamaan).
        $fp = @fopen($path, 'c+');
        if (! $fp) {
            abort(500, 'Gagal membuka file antrean.');
        }

        try {
            if (! flock($fp, LOCK_EX)) {
                abort(500, 'Gagal mengunci file antrean.');
            }

            rewind($fp);
            $raw = stream_get_contents($fp);
            $n = (int) trim((string) ($raw ?: '0'));
            $n++;
            if ($n < 1) {
                $n = 1;
            }
            if ($n > 9999) {
                $n = 9999; // batas struk 4 digit; operator lanjut manual esok/reset
            }

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string) $n);
            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }

        $number = 'A' . str_pad((string) $n, 3, '0', STR_PAD_LEFT);

        $params = ['taken' => $number];
        if ($storeId) {
            $params['store'] = $storeId;
        }

        return redirect()->route('display', $params);
    }
}
