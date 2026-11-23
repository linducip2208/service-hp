<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Kunci Periode (Tutup Buku) per toko — tanpa migration.
 * Disimpan di storage/app/period_lock.json keyed store_id => 'YYYY-MM-DD' (batas).
 * Aturan: transaksi bertanggal dokumen < batas DITOLAK (banding date-only).
 * Default tanpa kunci = bebas (tidak mengubah alur normal).
 */
class PeriodLock
{
    public const FILE = 'period_lock.json';

    /** @return array<string,string> key store_id => 'YYYY-MM-DD' */
    public static function all(): array
    {
        try {
            if (! Storage::disk('local')->exists(self::FILE)) return [];
            $arr = json_decode((string) Storage::disk('local')->get(self::FILE), true);
            if (! is_array($arr)) return [];
            $out = [];
            foreach ($arr as $k => $v) {
                $v = trim((string) $v);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) $out[(string) $k] = $v;
            }
            return $out;
        } catch (\Throwable $e) { return []; }
    }

    protected static function write(array $locks): void
    {
        Storage::disk('local')->put(self::FILE, json_encode($locks, JSON_PRETTY_PRINT));
    }

    /** Batas kunci toko (Y-m-d) atau null bila tidak dikunci. */
    public static function get(?int $storeId): ?string
    {
        if (! $storeId) return null;
        $all = self::all();
        return $all[(string) $storeId] ?? null;
    }

    public static function set(?int $storeId, string $date): ?string
    {
        if (! $storeId) return null;
        $date = substr(trim($date), 0, 10);
        $all = self::all();
        $all[(string) $storeId] = $date;
        self::write($all);
        return $date;
    }

    public static function clear(?int $storeId): void
    {
        if (! $storeId) return;
        $all = self::all();
        unset($all[(string) $storeId]);
        self::write($all);
    }

    /** Normalisasi ke 'YYYY-MM-DD' (date-only); null bila tak bisa diparse. */
    public static function toDateString($date): ?string
    {
        try {
            if ($date === null || $date === '') return null;
            if ($date instanceof \DateTimeInterface) return $date->format('Y-m-d');
            $s = trim((string) $date);
            if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) return $m[1];
            $ts = strtotime($s);
            return $ts ? date('Y-m-d', $ts) : null;
        } catch (\Throwable $e) { return null; }
    }

    /** True bila $date berada di periode terkunci ($date < batas). Tanpa kunci = false. */
    public static function isLocked(?int $storeId, $date): bool
    {
        $lock = self::get($storeId);
        if (! $lock) return false;
        $d = self::toDateString($date);
        if (! $d) return false;
        return $d < $lock;
    }

    /**
     * Tolak (422) bila tanggal dokumen < batas kunci. Tanpa kunci = lolos.
     * $date null/'' → dianggap hari ini (dokumen tanpa input tanggal = tanggal berjalan).
     */
    public static function check(?int $storeId, $date, string $label = 'Tanggal dokumen'): void
    {
        $lock = self::get($storeId);
        if (! $lock) return;
        $d = self::toDateString($date) ?? date('Y-m-d');
        if ($d < $lock) {
            abort(422, 'Periode sudah dikunci (tutup buku per ' . $lock . '). '
                . $label . ' ' . $d . ' berada di periode terkunci — hubungi owner untuk membuka kunci.');
        }
    }

    /**
     * Bila request membawa input tanggal eksplisit (mis. expense_date/payment_date/
     * transaction_date/tanggal/date), tegakkan kunci untuk tiap input yang terisi.
     * Dipakai modul yang tanggal bayarnya "hari ini" (Debt): hanya menolak bila ada
     * input tanggal yang jatuh di periode terkunci.
     */
    public static function checkRequestDates(?int $storeId, \Illuminate\Http\Request $request, string $label = 'Tanggal dokumen'): void
    {
        if (! self::get($storeId)) return;
        foreach (['expense_date', 'payment_date', 'transaction_date', 'tanggal', 'date', 'paid_at', 'payment_at'] as $key) {
            if ($request->filled($key)) {
                self::check($storeId, $request->input($key), $label);
            }
        }
    }

    public static function canManage(): bool
    {
        return in_array(auth()->user()->role ?? '', ['superadmin', 'owner'], true);
    }
}
