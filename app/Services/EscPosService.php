<?php

namespace App\Services;

/**
 * ESC/POS builder (thermal 58/80mm) + pengirim printer LAN/WiFi (port 9100).
 * Dipakai PrinterController untuk cetak langsung dari server ke printer jaringan.
 * Builder JS yang setara ada di public/js/printer.js (untuk Bluetooth/WebUSB).
 */
class EscPosService
{
    protected string $buf = '';
    protected int $cols;

    public function __construct(int $cols = 32)
    {
        $this->cols = $cols;
        $this->init();
    }

    public static function ascii(string $s): string
    {
        $s = \Normalizer::normalize($s, \Normalizer::FORM_D);
        $s = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        return preg_replace('/[^\x20-\x7E\n]/', '?', $s) ?? '';
    }

    public function init(): static { $this->buf .= "\x1B\x40"; return $this; }
    public function align(string $pos = 'left'): static {
        $this->buf .= "\x1B\x61" . chr(['left' => 0, 'center' => 1, 'right' => 2][$pos] ?? 0);
        return $this;
    }
    public function bold(bool $on = true): static { $this->buf .= "\x1B\x45" . chr($on ? 1 : 0); return $this; }
    public function doubleSize(bool $on = true): static { $this->buf .= "\x1D\x21" . chr($on ? 0x11 : 0x00); return $this; }
    public function line(string $ch = '-'): static { $this->buf .= str_repeat($ch, $this->cols) . "\n"; return $this; }
    public function text(string $s): static { $this->buf .= self::ascii($s) . "\n"; return $this; }
    public function feed(int $n = 3): static { $this->buf .= "\x1B\x64" . chr(min(255, $n)); return $this; }
    public function cut(): static { $this->feed(4); $this->buf .= "\x1D\x56\x01"; return $this; }

    /**
     * Pulsa buka laci kas (ESC p m t1 t2) — 5 byte.
     * $pin 0/1, $onMs/$offMs dalam 2ms unit (25=50ms, 250=500ms).
     */
    public function drawer(int $pin = 0, int $onMs = 25, int $offMs = 250): static
    {
        $this->buf .= self::cashDrawer($pin, $onMs, $offMs);
        return $this;
    }

    public static function cashDrawer(int $pin = 0, int $onMs = 25, int $offMs = 250): string
    {
        $pin = $pin ? 1 : 0;
        return "\x1B\x70" . chr($pin) . chr(max(0, min(255, $onMs))) . chr(max(0, min(255, $offMs)));
    }

    /** Tambah byte mentah (mis. hasil rasterFile) ke stream. */
    public function raw(string $bytes): static { $this->buf .= $bytes; return $this; }

    /**
     * Cetak gambar (logo/TTD) tengah halaman bila GD + file tersedia.
     * Gagal diam-diam (return $this tanpa byte) agar fallback teks pemanggil tetap jalan.
     */
    public function image(?string $absPath, ?int $maxW = null, int $threshold = 128): static
    {
        $cmd = self::rasterFile((string) ($absPath ?? ''), $maxW ?? self::maxRasterWidth($this->cols), $threshold);
        if ($cmd === '') return $this;
        $this->buf .= "\x1B\x61\x01" . $cmd . "\n";
        return $this;
    }

    // ---------- Raster bit-image ESC/POS (GS v 0) ----------

    public static function gdAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefrompng') && function_exists('imagecreatetruecolor');
    }

    /** Lebar raster maks (dot): 58mm/32kol → 384px, 80mm/48kol → 512px. */
    public static function maxRasterWidth(int $cols): int
    {
        return $cols <= 32 ? 384 : 512;
    }

    /** Path absolut logo branding toko (storage/app/public/logo/{id}.png) atau null. */
    public static function logoAbs($storeId): ?string
    {
        if (!$storeId) return null;
        $p = storage_path('app/public/logo/' . $storeId . '.png');
        if (is_file($p)) return $p;
        // Kompatibel branding lama: rel path tersimpan di branding.json.
        $rel = self::branding($storeId)['logo'] ?? null;
        if (is_string($rel) && $rel !== '') {
            $q = storage_path('app/public/' . ltrim($rel, '/'));
            if (is_file($q)) return $q;
        }
        return null;
    }

    /** Ubah path relatif storage (mis. signatures/x.png dari [SIG:...]) jadi absolut. */
    public static function storageAbs(?string $rel): ?string
    {
        if (!is_string($rel) || trim($rel) === '') return null;
        $p = storage_path('app/public/' . ltrim(trim($rel), '/'));
        return is_file($p) ? $p : null;
    }

    /** Cari path relatif TTD serah terima tiket service (marker [SIG:] di service_logs). */
    public static function serviceSignatureRel(\App\Models\ServiceTicket $t): ?string
    {
        try {
            $logs = $t->relationLoaded('logs') ? $t->logs : $t->logs()->orderBy('created_at', 'desc')->get();
            $sig = \App\Models\ServiceLog::handoverSignature($logs);
            if ($sig) return $sig;
        } catch (\Throwable $e) {}
        // Fallback konvensi nama file: signatures/{ticket_no aman}.png
        $safe = preg_replace('/[^A-Za-z0-9-_]/', '_', (string) ($t->ticket_no ?? ''));
        if ($safe !== '' && is_file(storage_path('app/public/signatures/' . $safe . '.png'))) {
            return 'signatures/' . $safe . '.png';
        }
        return null;
    }

    /**
     * Muat PNG/JPG → perintah raster ESC/POS "GS v 0" (1D 76 30 00 xL xH yL yH + data).
     * - Resize proporsional ke $maxW (tak pernah upscale), tinggi dibatasi 640px.
     * - Komposit ke latar putih (aman utk PNG transparan/TTD canvas), threshold B/W.
     * - Lebar dibulatkan ke kelipatan 8 (padding putih kanan); bit 1 = titik hitam, MSB dulu.
     * - Return '' bila GD tak ada / file tak valid — pemanggil wajib fallback teks.
     */
    public static function rasterFile(string $absPath, int $maxW = 384, int $threshold = 128): string
    {
        try {
            if (!self::gdAvailable() || $maxW <= 0 || !is_file($absPath)) return '';
            $info = @getimagesize($absPath);
            if (!$info || empty($info[0]) || empty($info[1])) return '';
            [$sw, $sh, $type] = $info;
            if ($sw <= 0 || $sh <= 0 || $sw > 4000 || $sh > 4000) return '';

            // Muat sumber: PNG dulu, lalu JPEG, terakhir tebakan generik.
            $src = false;
            if ($type === IMAGETYPE_PNG) $src = @imagecreatefrompng($absPath);
            if ($src === false && in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_JPEG2000], true)) {
                $src = @imagecreatefromjpeg($absPath);
            }
            if ($src === false) {
                $bin = @file_get_contents($absPath);
                if ($bin === false || strlen($bin) > 5 * 1024 * 1024) return '';
                $src = @imagecreatefromstring($bin);
            }
            if ($src === false) return '';

            // Skala: turun ke $maxW, lalu batasi tinggi 640px (struk tak kepanjangan).
            $scale = min(1.0, $maxW / $sw);
            $dw = (int) round($sw * $scale);
            $dh = (int) round($sh * $scale);
            if ($dh > 640) {
                $scale2 = 640 / $dh;
                $dw = max(8, (int) round($dw * $scale2));
                $dh = 640;
            }
            if ($dw < 8 || $dh < 1) { imagedestroy($src); return ''; }

            // Kanvas putih + resample (rata tepi transparan/anti-alias).
            $dst = imagecreatetruecolor($dw, $dh);
            if ($dst === false) { imagedestroy($src); return ''; }
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $dw, $dh, $white);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $dw, $dh, $sw, $sh);
            imagedestroy($src);

            // Threshold B/W → pad lebar ke kelipatan 8.
            $pw = (int) (ceil($dw / 8) * 8);
            $xb = (int) ($pw / 8);
            $threshold = max(1, min(254, $threshold));
            $data = '';
            for ($y = 0; $y < $dh; $y++) {
                for ($bx = 0; $bx < $xb; $bx++) {
                    $byte = 0;
                    for ($bit = 0; $bit < 8; $bit++) {
                        $x = $bx * 8 + $bit;
                        if ($x < $dw) {
                            $rgb = imagecolorat($dst, $x, $y);
                            $r = ($rgb >> 16) & 0xFF; $g = ($rgb >> 8) & 0xFF; $b = $rgb & 0xFF;
                            // Luminansi Rec.601 — akurat utk logo berwarna.
                            if ((0.299 * $r + 0.587 * $g + 0.114 * $b) < $threshold) {
                                $byte |= (0x80 >> $bit);
                            }
                        }
                    }
                    $data .= chr($byte);
                }
            }
            imagedestroy($dst);

            // Abaikan gambar nyaris kosong (mis. TTD gagal / logo putih polos): <0,2% piksel hitam.
            if (substr_count($data, "\x00") >= strlen($data) - max(1, (int) (strlen($data) * 0.002))) {
                // Tetap hitung eksak bila kecil; bila besar dan kosong → skip.
                if (strlen($data) > 2048) return '';
            }

            return "\x1D\x76\x30\x00"
                . chr($xb % 256) . chr(intdiv($xb, 256))
                . chr($dh % 256) . chr(intdiv($dh, 256))
                . $data;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Baris 2 kolom: kiri .... kanan */
    public function row(string $left, string $right): static
    {
        $left = self::ascii($left); $right = self::ascii($right);
        $space = $this->cols - strlen($left) - strlen($right);
        $this->buf .= $space >= 1
            ? $left . str_repeat(' ', $space) . $right . "\n"
            : $left . "\n" . str_pad($right, $this->cols, ' ', STR_PAD_LEFT) . "\n";
        return $this;
    }

    public function wrap(string $s): static
    {
        foreach (explode("\n", wordwrap(self::ascii($s), $this->cols, "\n", true)) as $l) {
            $this->buf .= $l . "\n";
        }
        return $this;
    }

    /** QR Model 2 — didukung mayoritas printer thermal modern. */
    public function qr(string $data, int $size = 6): static
    {
        $data = substr($data, 0, 400);
        $this->buf .= "\x1D\x28\x6B\x04\x00\x31\x41\x32\x00";          // model 2
        $this->buf .= "\x1D\x28\x6B\x03\x00\x31\x43" . chr($size);      // module size
        $this->buf .= "\x1D\x28\x6B\x03\x00\x31\x45\x31";               // EC level M
        $len = strlen($data) + 3;
        $this->buf .= "\x1D\x28\x6B" . chr($len % 256) . chr(intdiv($len, 256)) . "\x31\x50\x30" . $data;
        $this->buf .= "\x1D\x28\x6B\x03\x00\x31\x51\x30";               // print
        $this->buf .= "\n";
        return $this;
    }

    public function bytes(): string { return $this->buf; }

    // ---------- Struk siap cetak ----------

    public static function saleReceipt(\App\Models\Sale $sale, int $cols = 32): string
    {
        $e = new self($cols);
        $store = $sale->store;
        $b = self::branding($sale->store_id ?? null);
        // Logo branding (raster GS v 0) di awal struk bila file ada + GD tersedia.
        // Fallback anggun: image() diam-diam tanpa byte bila tak bisa → teks nama toko di bawah tetap dicetak.
        $e->image(self::logoAbs($sale->store_id ?? null));
        $e->align('center')->bold()->text($store->name ?? 'ServiceHP')->bold(false);
        if ($b['header'] !== '') {
            foreach (self::lines($b['header']) as $l) $e->text($l);
        } else {
            if ($store?->address) $e->text($store->address);
            if ($store?->phone) $e->text($store->phone);
        }
        $e->line()
          ->align('left')
          ->row('No:', $sale->invoice_no)
          ->row('Tgl:', $sale->created_at->format('d/m/Y H:i'))
          ->row('Kasir:', $sale->user->name ?? '-')
          ->row('Plg:', $sale->customer->name ?? 'Umum')
          ->line();
        foreach ($sale->items as $it) {
            $e->wrap($it->description);
            $e->row("  {$it->qty} x " . number_format($it->price, 0, ',', '.'), number_format($it->subtotal, 0, ',', '.'));
        }
        $e->line()
          ->row('Subtotal', number_format($sale->subtotal, 0, ',', '.'))
          ->row('Diskon', number_format($sale->discount, 0, ',', '.'));
        if ($sale->trade_in_value > 0) $e->row('Tukar tambah', '-' . number_format($sale->trade_in_value, 0, ',', '.'));
        if ((float) ($sale->tax ?? 0) > 0) {
            $e->row('Pajak', number_format((float) $sale->tax, 0, ',', '.'));
        }
        $e->bold()->row('TOTAL Rp', number_format($sale->total, 0, ',', '.'))->bold(false);
        $e->row("Bayar({$sale->payment_method})", number_format($sale->paid_amount, 0, ',', '.'));
        $change = (float) $sale->paid_amount - (float) $sale->total;
        if ($change > 0) {
            $e->bold()->row('KEMBALIAN Rp', number_format($change, 0, ',', '.'))->bold(false);
        }
        $e->row('Sisa(' . $sale->payment_status . ')', number_format($sale->remaining, 0, ',', '.'));
        $e->line()->align('center');
        if ($b['footer'] !== '') {
            foreach (self::lines($b['footer']) as $l) $e->text($l);
        } else {
            $e->text('Terima kasih atas kunjungan Anda')
              ->text('Garansi & retur maks 7 hari + struk');
        }
        $e->text('Lacak service: ' . url('/track'))
          ->cut();
        return $e->bytes();
    }

    public static function serviceReceipt(\App\Models\ServiceTicket $t, int $cols = 32): string
    {
        $e = new self($cols);
        $b = self::branding($t->store_id ?? null);
        $e->align('center')->bold()->doubleSize()->text('SERVICE')->doubleSize(false)->bold(false);
        // Logo branding di bawah judul SERVICE (konsisten dgn struk sale) — fallback teks bila raster gagal.
        $e->image(self::logoAbs($t->store_id ?? null));
        $e->text($t->store->name ?? 'ServiceHP');
        if ($b['header'] !== '') {
            foreach (self::lines($b['header']) as $l) $e->text($l);
        } elseif ($t->store?->phone) {
            $e->text($t->store->phone);
        }
        $e->line()->align('left')
          ->row('Tiket:', $t->ticket_no)
          ->row('Tgl:', $t->created_at->format('d/m/Y H:i'))
          ->row('Plg:', ($t->customer->name ?? '-') . ' ' . ($t->customer->phone ?? ''))
          ->row('Device:', trim(($t->product_brand ?? '') . ' ' . ($t->product_type ?? '')));
        if ($t->imei) $e->row('IMEI:', $t->imei);
        $e->line()->wrap('Keluhan: ' . $t->complaint);
        if ($t->condition_note) $e->wrap('Kondisi: ' . $t->condition_note);
        $e->line()
          ->row('Estimasi', 'Rp ' . number_format($t->estimate_cost, 0, ',', '.'))
          ->row('DP', 'Rp ' . number_format($t->dp, 0, ',', '.'))
          ->row('Status', strtoupper($t->status))
          ->line()->align('center')
          ->qr(url('/track') . '?no=' . $t->ticket_no)
          ->text($t->ticket_no)
          ->text('Scan utk lacak status service');
        if ($b['footer'] !== '') {
            foreach (self::lines($b['footer']) as $l) $e->text($l);
        } else {
            $e->text('Wajib bawa tanda terima saat ambil');
        }
        // TTD serah terima (storage/app/public/signatures/*.png via marker [SIG:] di service_logs).
        // Threshold 170: garis tipis canvas anti-alias tetap tercetak. Fallback teks bila file/GD tak ada.
        $sigAbs = self::storageAbs(self::serviceSignatureRel($t));
        if ($sigAbs) {
            $e->align('center')->text('TTD Pelanggan:')
              ->image($sigAbs, null, 170);
        }
        $e->cut();
        return $e->bytes();
    }

    /**
     * Kirim byte mentah ke printer LAN/WiFi (port 9100 / RAW).
     * @return array{ok:bool,msg:string}
     */
    public static function sendLan(string $ip, int $port, string $bytes): array
    {
        $fp = @fsockopen($ip, $port, $errno, $errstr, 5);
        if (!$fp) return ['ok' => false, 'msg' => "Tidak bisa konek ke $ip:$port ($errstr)"];
        stream_set_timeout($fp, 10);
        $w = fwrite($fp, $bytes);
        fclose($fp);
        return $w ? ['ok' => true, 'msg' => "Terkirim $w byte ke $ip:$port"] : ['ok' => false, 'msg' => 'Gagal menulis ke printer'];
    }

    // ---------- Manajemen daftar printer (JSON) ----------

    public static function file(): string { return storage_path('app/printers.json'); }

    /** @return array<int,array{id:string,name:string,ip:string,port:int,width:int,is_default:bool}> */
    public static function all(): array
    {
        $f = self::file();
        if (!file_exists($f)) return [];
        return json_decode(file_get_contents($f), true) ?: [];
    }

    public static function save(array $list): void
    {
        file_put_contents(self::file(), json_encode(array_values($list), JSON_PRETTY_PRINT));
    }

    // ---------- Branding struk per toko (header/footer/logo) ----------

    public static function brandingFile(): string { return storage_path('app/branding.json'); }

    /** @return array{header:string,footer:string,logo:string|null,auto_print:bool,auto_drawer:bool} */
    public static function branding($storeId): array
    {
        $all = [];
        $f = self::brandingFile();
        if (file_exists($f)) $all = json_decode((string) file_get_contents($f), true) ?: [];
        $b = $all[(string) ($storeId ?? '')] ?? [];
        return [
            'header' => trim((string) ($b['header'] ?? '')),
            'footer' => trim((string) ($b['footer'] ?? '')),
            'logo' => $b['logo'] ?? null,
            'auto_print' => !empty($b['auto_print']),
            'auto_drawer' => !empty($b['auto_drawer']),
        ];
    }

    public static function saveBranding($storeId, array $data): void
    {
        $f = self::brandingFile();
        $all = file_exists($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
        $prev = is_array($all[(string) $storeId] ?? null) ? $all[(string) $storeId] : [];
        $all[(string) $storeId] = [
            'header' => trim((string) ($data['header'] ?? '')),
            'footer' => trim((string) ($data['footer'] ?? '')),
            'logo' => $data['logo'] ?? null,
            'auto_print' => array_key_exists('auto_print', $data) ? !empty($data['auto_print']) : !empty($prev['auto_print']),
            'auto_drawer' => array_key_exists('auto_drawer', $data) ? !empty($data['auto_drawer']) : !empty($prev['auto_drawer']),
            'updated_at' => now()->toDateTimeString(),
        ];
        file_put_contents($f, json_encode($all, JSON_PRETTY_PRINT));
    }

    /** Path web logo toko (storage/app/public/logo/{id}.png) atau null bila belum ada. */
    public static function logoUrl($storeId): ?string
    {
        if (!$storeId) return null;
        $rel = 'logo/' . $storeId . '.png';
        return file_exists(storage_path('app/public/' . $rel)) ? 'storage/' . $rel : null;
    }

    /** Pecah teks multi-baris menjadi array baris rapi. */
    public static function lines(string $s): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $s) ?: [])));
    }
}
