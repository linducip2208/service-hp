<?php

namespace App\Support;

use App\Models\Store;

/**
 * Prefix kode toko untuk nomor dokumen (Continuous 219).
 *
 * Konvensi: PREFIX DI DEPAN — bila stores.code terisi, nomor baru menjadi
 * `{CODE}-{basis}` (mis. PST-INV-20261123-0421); bila kosong, dipakai format
 * lama persis (fallback, tanpa prefix). Data lama TIDAK diubah — helper ini
 * hanya dipakai saat generate nomor baru.
 *
 * Kode disanitasi: uppercase + hanya A-Z0-9 (strip spasi/simbol) agar aman
 * untuk nomor dokumen, carian LIKE, dan nama file (tanda tangan dsb).
 */
final class DocNumber
{
    /**
     * Bangun prefix dari kode toko mentah: "PST" → "PST-", "" / null → "".
     */
    public static function prefix(?string $storeCode): string
    {
        $code = strtoupper((string) ($storeCode ?? ''));
        $code = (string) preg_replace('/[^A-Z0-9]/', '', $code);

        return $code !== '' ? $code . '-' : '';
    }

    /**
     * Ambil prefix langsung dari id toko (query ringan ke stores.code).
     * Gagal/unknown → "" (fallback format lama).
     */
    public static function prefixForStore(?int $storeId): string
    {
        if (!$storeId) {
            return '';
        }

        try {
            $code = Store::whereKey($storeId)->value('code');
        } catch (\Throwable $e) {
            return '';
        }

        return self::prefix(is_string($code) ? $code : (string) ($code ?? ''));
    }

    /**
     * Tempel prefix ke nomor dasar: withPrefix('INV-20261123-0001', 'PST')
     * → 'PST-INV-20261123-0001'; kode kosong → nomor dasar apa adanya.
     */
    public static function withPrefix(string $base, ?string $storeCode): string
    {
        return self::prefix($storeCode) . $base;
    }
}
