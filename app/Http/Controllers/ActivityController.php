<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Log Aktivitas file-based (tanpa DB/tabel baru).
 * Membaca storage/logs/activity-Y-m-d.log, maksimal 500 baris terakhir,
 * plus section terpisah storage/logs/auth-fail-Y-m-d.log (50 terakhir).
 * Khusus superadmin/owner.
 */
class ActivityController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(in_array(auth()->user()->role ?? '', ['superadmin', 'owner']), 403, 'Hanya owner/superadmin.');

        $date = (string) $request->query('date', now()->format('Y-m-d'));

        // Validasi format ketat YYYY-MM-DD + tanggal kalender valid.
        // Regex ini sekaligus mencegah path traversal (tanpa / . atau karakter lain).
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            abort(404, 'Format tanggal tidak valid (gunakan YYYY-MM-DD).');
        }
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        if (! checkdate($m, $d, $y)) {
            abort(404, 'Tanggal tidak valid.');
        }

        $path = storage_path('logs' . DIRECTORY_SEPARATOR . 'activity-' . $date . '.log');

        $lines = [];
        $exists = is_file($path);
        if ($exists) {
            $all = @file($path, FILE_IGNORE_NEW_LINES);
            if (is_array($all)) {
                // 500 baris terakhir, terbaru di atas.
                $lines = array_reverse(array_slice($all, -500));
            }
        }

        // Section terpisah: gagal login (50 terakhir, terbaru di atas).
        $failPath = storage_path('logs' . DIRECTORY_SEPARATOR . 'auth-fail-' . $date . '.log');
        $failLines = [];
        $failExists = is_file($failPath);
        if ($failExists) {
            $failAll = @file($failPath, FILE_IGNORE_NEW_LINES);
            if (is_array($failAll)) {
                $failLines = array_reverse(array_slice($failAll, -50));
            }
        }

        return view('activity.log', [
            'date' => $date,
            'lines' => $lines,
            'exists' => $exists,
            'total' => count($lines),
            'failLines' => $failLines,
            'failExists' => $failExists,
            'failTotal' => count($failLines),
        ]);
    }
}
