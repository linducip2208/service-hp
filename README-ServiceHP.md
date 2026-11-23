# ServiceHP — POS • Inventory IMEI • Service Center • Multi-Toko

Laravel 12 + MySQL + Tabler UI 100% + PWA. Multi-store via `session('active_store_id')`.

## Jalankan (Laragon / Windows)

```powershell
cd "D:\project laravel\servicehp"
php artisan migrate --force
php artisan db:seed --force
php artisan serve --host=127.0.0.1 --port=8000
```
Buka http://localhost:8000 — login di bawah.

## Akun demo (password: `password`)
| Email | Role |
|---|---|
| admin@servicehp.id | superadmin |
| owner@servicehp.id | owner |
| kasir@servicehp.id | kasir |
| teknisi@servicehp.id | teknisi (komisi 10%) |

## Modul & kapabilitas

### POS / Kasir — `/pos`
- Scan barcode / ketik SKU / IMEI + **Enter**; stok menipis & habis ditandai otomatis.
- Qty, promo/voucher (auto-suggest dari `/promos`, kode manual opsional), tukar tambah inline, cicilan/DP.
- **Tahan struk (F9)**: `hold_cart` + `hold_name` — struk ditahan per slot, dilanjutkan/dihapus dari panel "Struk Ditahan" (`?drop_held=` / `?resume_held=`).
- **Split payment**: checkbox split — gabungan tunai + transfer/QRIS, total harus pas (selisih > Rp 1 ditolak).
- Shortcut: **F2** fokus scan, **F4** bayar tunai, **F9** tahan struk, **Esc** reset.
- **Bayar → struk** (`/pos/print/{sale}`) → cetak browser / Bluetooth thermal 58-80mm / kirim WA ke pelanggan; **Struk Hadiah** (`?gift=1`, tanpa harga) via tombol 🎁 di halaman struk.
- **Tutup Kasir** langsung dari POS (`/sales/closing`).

### Riwayat & Transaksi
- `/sales` — daftar, detail (`/sales/{sale}`), invoice (`/sales/{sale}/invoice`), hapus (retur via menu khusus), pelunasan cicilan/DP (`POST /sales/{sale}/pay`).
- `/sales/closing` — **Tutup Kasir / Handover**: hitung fisik per akun kas, selisih otomatis, berita acara + tanda tangan shift; kirim rekap via WA (`closing/wa`) dan **Kirim Digest Sekarang** (`closing/digest`).
- Retur & refund `/returns`, tukar tambah `/tradeins`, beli HP bekas `/secondhand`, konsinyasi `/consignments` (+ pelunasan `POST /consignments/{id}/settle`).

### Inventory
- `/products` — CRUD produk + foto, barcode (JsBarcode, siap scanner USB), aktif/nonaktif.
- `/products/bulk` — **harga massal**: filter kategori/brand/type + pencarian, ubah harga beli/jual per baris atau massal (naik/turun % atau nominal).
- `/products/reorder` — **saran belanja**: stok ≤ minimum + rekomendasi qty usulan (buffer 7 hari dari rata-rata penjualan 30 hari).
- `/products/export` — unduh CSV gaya Excel Indonesia (delimiter `;` + BOM).
- `/products/import` — import CSV (lihat "Format CSV import" di bawah).
- `/units` — unit / IMEI per produk (masuk, terjual, retur) + timeline per unit (`/units/{unit}/timeline`).
- `/stocks/opname` — stok opname (selisih tercatat sebagai mutasi), `/stocks/mutations` — mutasi & **transfer antar toko**.

### Service Center
- `/services` — tiket `SVC-…`: pelanggan, tipe HP, IMEI, keluhan, kondisi + foto; diagnosa + estimasi → `menunggu_persetujuan`; persetujuan WA; tambah sparepart (`POST /services/{service}/sparepart`); ganti status (`POST /services/{service}/status`); QC → `siap_diambil` (notif WA otomatis/manual); serah terima + pelunasan; cetak nota (`/services/{service}/print`) + garansi.
- `/services/report` — **laporan service**: periode + toko, rekap status/omzet/teknisi.
- `/warranties` — garansi + klaim (`POST /warranties/{warranty}/claim`).
- `/bookings` — booking online masuk (setujui/tolak/hapus) + pengingat WA (`POST /bookings/{booking}/remind`). Publik: `/booking` (form) & `/track` (lacak status tiket).
- `/technicians` — teknisi & komisi; `/technicians/report` — **laporan komisi** (periode, terbayar/belum); bayar komisi (`POST /technicians/{id}/pay`).

### Keuangan
- `/cash` — kas masuk/keluar per akun; `/cash/transfer` — **transfer antar akun kas**; `/cash/closing` — rekap kas harian; `/cash/flow` — **arus kas** per periode; `/cash/{id}/voucher` + `/cash/vouchers?ids[]=` — **voucher kas satuan & massal** (maks 50).
- `/expenses` — pengeluaran + **budget per kategori per bulan** (`POST /expenses/budget`, file `storage/app/budget.json`) + **tampilan tahunan** (`?tahun=YYYY`: budget vs realisasi Jan–Des).
- `/debts` — hutang & piutang (tambah/bayar, `POST /debts/{id}/due-date` ubah jatuh tempo); `/debts/statements` — **cetak tagihan massal**, `POST /debts/bulk-pay` — **bayar massal**.
- `/purchases` — pembelian supplier (bayar `POST /purchases/{id}/pay`, tandai diterima `POST /purchases/{id}/received`, kirim WA `POST /purchases/{id}/wa`); `/purchases/bulk-print` — **cetak PO massal** terpilih.
- `/stocks/valuation` — **valuasi stok** (nilai modal tertanam per produk/toko); `/stocks/deadstock` — **deadstock** (stok macet + saran tindak lanjut); `/stocks/opname/import` — **import hasil opname CSV**.
- `/reports/profit?export=csv` — laba rugi per toko + periode, unduh CSV (`;` + BOM). Versi lengkap: **neraca sederhana** (kas + modal + piutang vs hutang), **ROI** (% + per-Rp 1.000 modal), **estimasi PPh Final UMKM 0,5%** (info, bukan angka resmi), **MoM & YoY** (bulan lalu + bulan sama tahun lalu), **ringkasan narasi otomatis**, stok menipis & hutang jatuh tempo.

### Target omzet & teknisi
- Target toko: `POST /stores/{store}/target` (bulanan, `storage/app/targets.json`) — Dashboard menampilkan **widget kejar target harian** (sisa target ÷ sisa hari) + **progres tahunan** Jan–Des.
- Target teknisi: `POST /technicians/{id}/target` — tampil di laporan komisi.

### CRM lanjutan (RFM, repeat, merge, siaran)
- `/customers` — daftar + **kartu repeat** (pelanggan aktif vs repeat 30 hari + %) dan **peringatan kemungkinan duplikat**; `POST /customers/merge` — **merge duplikat** (transaksi/service pindah ke induk).
- Detail pelanggan: **skor RFM** (jumlah belanja, hari sejak belanja terakhir, rata-rata — flag *dormant* >90 hari), total belanja, **kirim tagihan via WA** (`POST /customers/{customer}/statement-wa`), **kartu member** (`/customers/{customer}/member-card`) + **cetak massal** (`/customers/member-cards?ids[]=`), **vCard** (`/customers/{customer}/vcard`, unduh kontak `.vcf`).
- `/customers/broadcast` — **siaran WA massal** ke pelanggan terpilih (tanpa gateway = antri di log).
- Merge juga tersedia untuk **produk** (`POST /products/merge`), **supplier** (`POST /suppliers/merge`), **brand** (`POST /brands/merge`), **kategori** (`POST /categories/merge`) — masing-masing dengan deteksi **kemungkinan duplikat** di halaman index. vCard juga untuk supplier (`/suppliers/{supplier}/vcard`) & teknisi (`/technicians/{id}/vcard`).

### Promo engine
- `/promos` — tipe `diskon_persen / diskon_rupiah / bundling / voucher / member`, validasi cap 100% + cek semantik per tipe; **hitung mundur** otomatis di daftar ("berakhir dalam X hari"); **QR promo** per baris + **lembar QR massal** (`/promos/qr-sheet`, cetak tempel di etalase); **matikan massal** (`POST /promos/bulk-off`); **duplikat promo** (`POST /promos/{promo}/duplicate`); produk juga bisa **diduplikat** (`POST /products/{product}/duplicate`).

### Cetak massal & dokumen
- Invoice: `/sales/{sale}/invoice` (**A4**, logo + branding), `/sales/invoices/bulk?ids[]=` — **gabung ≤50 invoice** dalam 1 halaman cetak; kwitansi per cicilan `/sales/payments/{payment}/receipt` (nomor `KWT-…` + tanda tangan).
- Service: `/services/bulk-tickets?ids[]=` — **tiket massal**; garansi: `/warranties/{warranty}/card` + `/warranties/cards?ids[]=` — **kartu garansi massal**.
- Produk: halaman detail punya **cetak label barcode rak** (grid 3 kolom, qty 1–60, JS lokal); stok tampil **lokasi gudang** per toko.
- Nota kecil: `/printers/receipt/sale/{sale}` & `/printers/receipt/service/{service}` (kirim langsung ke printer LAN / WA).

### Service — QC, handover & TTD
- Detail tiket: **checklist QC** (Fungsi nyala, Sinyal, Kamera, Charging, Tombol, Speaker — tersimpan sebagai log `QC-CHECK:{json}`), **checklist serah terima** (`HANDOVER-CHECK:{json}`) + **tanda tangan pelanggan** (`[SIG:nama]`), foto maks 4 (2 MB/foto, `storage/service`), prioritas (`rendah/normal/mendesak`, `POST /services/{service}/priority`, sortir `?sort=priority`), ETA otomatis per prioritas.
- `/services/report` — laporan + rekap teknisi; garansi bisa dikonversi jadi tiket (`POST /warranties/{warranty}/ticket`); slip komisi (`/technicians/{id}/slip`) + bayar semua (`POST /technicians/pay-all`); booking: kalender (`/bookings/calendar`) + setujui massal (`POST /bookings/confirm-selected`).

### Update gelombang 96–99
- **Slip komisi massal**: `GET /technicians/slips/bulk` (`?bulan=` + filter teknisi) — cetak/gabung slip sekaligus; satuan tetap `/technicians/{id}/slip`.
- **Digest pagi 07:00**: `php artisan digest:morning [--store=id/kode] [--date=Y-m-d] [--dry-run]` — kesiapan hari ini per toko (jadwal `routes/console.php` 07:00); harian 21:00 + mingguan Senin 07:00 tetap jalan.
- **Jejak pindah lokasi**: opname yang hanya ganti `location` (qty tetap) dicatat di `/stocks/mutations` sebagai mutasi qty 0 (`pindah lokasi 'A' → 'B'`), tanpa merusak agregat; riwayat per produk di detail produk.
- **Promo bulk on/off**: `POST /promos/bulk-on` (aktifkan terpilih) melengkapi `POST /promos/bulk-off`; dipakai dari daftar `/promos` via checkbox.
- **Audit diskon**: `/sales/closing` — rasio diskon÷omzet per kasir, badge PANTAU >15% / AWAS >25% (omzet Rp 0 → 0%).
- **Alert jual rugi**: `/products?rugi=1` — filter produk aktif dengan harga jual < harga beli (+ penghitung di index).
- **Total diskon laporan**: `/reports/profit` — total diskon periode = diskon header (`sales.discount`) + diskon item (`sale_items.discount`).
- **Tak-pernah-laku**: `/stocks/deadstock?never_sold=1` — saring deadstock yang belum pernah terjual sama sekali.

### Update gelombang 100–119- **Pindah toko massal**: tiket service (`POST /services/transfer-selected`, satuan `POST /services/{service}/transfer`), booking (`POST /bookings/transfer-selected`), pelanggan (`POST /customers/move-store`) — untuk pembagian ulang beban antar cabang.
- **Aksi tiket massal**: set prioritas ≤50 tiket (`POST /services/priority-selected`, tiket final dilewati), pengingat ambil massal khusus `siap_diambil` (`POST /services/remind-pickup-selected`), pengingat teknisi massal (`POST /services/remind-technician-selected`).
- **Aksi booking massal**: setujui (`POST /bookings/confirm-selected`), batalkan (`POST /bookings/cancel-selected`), ingatkan via WA (`POST /bookings/remind-selected`), pindah toko (`POST /bookings/transfer-selected`); kalender di `/bookings/calendar`.
- **Gabung duplikat otomatis 1-klik** (`POST */merge-exact`, pelanggan/produk/supplier/brand/kategori): hanya grup identik penuh yang digabung (pelanggan = no. HP sama; produk = nama+brand+tipe+spek+harga sama; lintas-toko dilewati), primary = ID terkecil, tiap grup transaksi sendiri + laporan per grup.
- **Produk**: set merek/kategori massal ≤100 (`POST /products/bulk-assign`), ubah min-stok per produk (`POST /products/{product}/min-stock`), kirim daftar restock per supplier via WA dari Saran Belanja (`POST /products/reorder/wa`).
- **Promo antar-toko**: `POST /promos/{promo}/duplicate-all` — salin promo ke semua toko aktif sekaligus (kode turunan `KODE-KODETOKO`, nonaktif, tanggal dikosongkan; toko asal/duplikat kode dilewati).
- **Pengguna massal** (`POST /users/bulk-role`, `POST /users/bulk-status`): ubah role / aktif-nonaktif sekaligus, akun sendiri dilewati + superadmin terakhir dilindungi.
- **Pengeluaran**: ubah kategori massal (`POST /expenses/bulk-category`) untuk merapikan salah input sekaligus.
- **Pengingat WA massal**: hutang (`POST /debts/bulk-remind`) & garansi aktif ≤50 (`POST /warranties/remind-selected`).
- **Kunci periode** (`POST /cash/closing/lock`, `DELETE /cash/closing/lock`, owner/superadmin): tolak transaksi bertanggal sebelum batas — cegah ubah histori setelah tutup buku; tampil di `/cash/closing`.
- **Log aktivitas** (`/activity` ringkasan + `/activity/log?date=YYYY-MM-DD` file-based, 500 baris terakhir, khusus owner/superadmin).
- **Otomatisasi digest** (jalan tiap digest harian/mingguan/pagi): nonaktifkan promo kedaluwarsa, pengingat garansi H-7 + pengingat awal H-30 s/d H-8 (sekali selamanya), pengingat ambil & tagihan hutang, **auto-batalkan booking basi** (+ WA 1x), **eskalasi tiket lama** — semua tertera di pesan digest (`php artisan digest:daily|digest:weekly|digest:morning [--dry-run]`).

### Update gelombang 120–124
- **Security sweep**: `/settings/*` (WA + printer) & `/stores` hanya owner/admin/superadmin; kirim nota printer LAN/WA dicek per-toko aktif; tombol bayar komisi & aksi gudang disembunyikan untuk role tanpa hak; publik di-throttle (`POST /login` 10/mnt, `POST /booking` 10/mnt, `POST /track` 30/mnt).
- **Gate komisi**: `POST /technicians/{id}/pay` + `POST /technicians/pay-all` hanya owner/admin/superadmin (teknisi dilarang membayar komisi sendiri); tombol disembunyikan bila tanpa hak.
- **Blokir jual rugi**: `POST /pos` — kasir/teknisi ditolak 422 bila harga < modal (modal unit IMEI bila ada, fallback harga beli produk); owner/admin/superadmin/gudang bebas.
- **Cap diskon manual 30%**: `POST /pos` — diskon manual kasir/teknisi maks 30% × subtotal sebelum promo (promo & diskon per-baris tidak dicap); lebih → 422.
- **Bukti wajib**: `POST /expenses` nominal > Rp1jt wajib foto `bukti`; `POST /purchases` total > Rp5jt wajib `nota_foto` (marker `[BUKTI:path]`, tanpa migration); `POST /cash` & `POST /sales/{sale}/pay` dukung `bukti` opsional.
- **Gate nominal besar**: `POST /cash` keluar > Rp10jt & `POST /returns` (retur/refund/void) > Rp2jt hanya owner/admin/superadmin (422 + minta login ulang role berhak).
- **Klaim garansi massal**: `POST /warranties/claim-selected` (maks 50, hanya status aktif, lapor ok/lewat/gagal per baris).
- **Pindah toko massal**: `POST /services/transfer-selected`, `POST /bookings/transfer-selected`, `POST /customers/move-store` — bagi ulang beban antar cabang (satuan `POST /services/{service}/transfer`).
- **Audit stok negatif**: `/health` cek stok `qty < 0` tampil saja (maks 100) — koreksi via `POST /stocks/opname`; tanpa tombol auto-fix (4 aksi aman hanya untuk unit terjual/service-yatim/komisi yatim/garansi kedaluwarsa).

### Update gelombang 131–144
- **Min stok per toko** — `POST products/{product}/min-stock` (override, NULL ikut global) + `POST products/bulk-assign` (`assign_min_stock` massal).
- **Rata-rata nilai tiket** — di `/services/report` (avg total + avg selisih aktual−estimasi + avg tunggu per teknisi).
- **Lokasi gudang** — filter `?location=` di opname, import CSV `sku,qty[,location]`, tombol cetak label lokasi, jejak pindah tercatat qty 0.
- **Arus kas tahunan** — `/cash/flow?tahun=YYYY` (12 bulan + combo chart + drill-down + CSV).
- **Tren tahunan laba rugi** — grafik Jan–Des omzet + laba di `/reports/profit`; **salin target** ke tahun depan via `POST stores/targets/copy-next-year`.
- **Import/template semua master** — produk, pelanggan, supplier, promo, merek, kategori (delimiter auto, BOM, contoh cocok importer).
- **Promo massal** — perpanjang +30 hari (`POST promos/bulk-extend`, basis hari ini), on/off massal.
- **Backup lengkap** — ZIP CSV ikut `settings/*.json` + `README_RESTORE.txt`; `POST settings/backup/clear-cache`; audit file yatim di `/settings/storage` (hapus terpilih).
- **Peta toko** — Buka Maps + salin link + share WA dari kolom alamat (tanpa route khusus).
- **Set teknisi massal** — `POST services/technician-selected` (maks 50, final/sudah-sama dilewati, log per baris): rebalance tiket antar teknisi.
- **Konversi booking** — kartu rasio tiket-terbuat di `/bookings` + link `Tiket #id`; export CSV `?export=csv` (filter-aware).
- **Raster thermal** — logo toko + TTD tercetak di struk LAN (`GS v 0`); self-test lapor byte + status logo + QR sampel.

### Update gelombang 150–153
- **Raster thermal BT/USB gambar** — `PrintHub.printImage(area, transport, widthPx)` (html2canvas → raster ESC/POS) via tombol 📡 BT Gambar / 🔌 USB Gambar di `/pos/print/{sale}` & `/services/{service}/print` (lambat ±10-30 dtk, butuh kertas cukup) + PNG/Bagikan.
- **Self-test printer lengkap** — `POST /settings/printers/{id}/test` (tombol Test di `/settings/printers`): struk teks + logo raster bila ada + QR sampel `SERVICEHP|TEST|…`, lapor byte struk + status logo + QR di flash message.
- **Generator kode promo** — tombol 🎲 di `/promos/create` (JS `promo-random`): suffix acak 4–6 char A-Z0-9 dari prefix kode, masih bisa diedit manual.
- **Template ucapan kado** — dropdown ⚡ di `/pos` (HBD / Terima Kasih / Lekas Sembuh / Sukses Selalu → isi kolom note, bebas diedit) → tersimpan prefix `[UCAPAN]`, tampil besar di struk hadiah `?gift=1`.
- **Nomor antrean walk-in** — `POST /queue/take` (publik, throttle 20/mnt, counter `storage/app/queue-YYYYMMDD.txt` format A001… reset harian) → redirect `/display?taken=Axxx` + slip nomor siap cetak (`#queue-slip`).
- **Panggil berikutnya TV** — tombol 📢 di `/display` (per perangkat, `localStorage tv_called_*`): tandai tiket `siap_diambil` satu-per-satu + chime + banner, ↺ Ulangi untuk reset ronde.
- **Mode kasir/fullscreen** — tombol di `/pos` (`body.kasir-mode`, Fullscreen API): sembunyikan header, perbesar scan & tombol bayar; pilihan tersimpan `localStorage pos_kasir_mode`.
- **Dark mode detail** — toggle 🌙/☀️ navbar (`localStorage servicehp-theme`, hormati `prefers-color-scheme` saat pertama, set pre-paint anti-FOUC via `data-bs-theme`).
- **Ekspor booking CSV** — `/bookings?export=csv` (hormati filter status/search + toko, `;` + BOM, maks 5000 baris: kode/nama/HP/device/keluhan/jadwal/status/tiket).
- **Min stok per toko** — `POST /products/{product}/min-stock` (override per toko, NULL ikut global) + `POST /products/bulk-assign` (`assign_min_stock` massal); semua alert pakai nilai efektif.
- **Rata-rata nilai tiket** — kartu di `/services/report`: avg total (`$avgTiket`), avg selisih aktual−estimasi (`$avgSelisih`), avg pengerjaan (`$avgHari`) + avg tunggu per teknisi.

### Update gelombang 155–159
- **Jadwal angsuran + cetak** — `GET /sales/{sale}` kartu Jadwal Angsuran (nominal/bulan, jatuh tempo, status lunas/belum) + tombol Cetak Jadwal (`printStruk('jadwal-struk-…')`).
- **WA rekap + jadwal cicilan** — `POST /customers/{customer}/statement-wa` rekap bulanan kini sisipkan JT berikutnya per invoice cicilan (tenor, sisa, `ke-N BELUM`) + total sisa cicilan.
- **Grafik komisi** — `GET /technicians/report?bulan=` canvas `#chartKomisi` per teknisi (menunggu vs dibayar, hover rincian).
- **Pengingat opname di digest** — `POST /sales/closing/digest` / `php artisan digest:daily` tempel baris opname bila terakhir >30 hari (`stock_movements type=opname`, belum pernah = tampil).
- **Struk WA otomatis** — `POST /pos` checkbox `send_wa` (default mati) kirim struk via WA langsung setelah bayar (pesan = tombol manual, log `struk_sale`, gagal tak batalkan transaksi).
- **Tanggal habis stok** — `GET /products/reorder` kolom Tahan (hari) + Habis tgl (hari ini + `days_cover`, ∞ bila tanpa jual 30 hari, badge <7 hr).
- **Filter tanpa foto** — `GET /products?no_photo=1` produk aktif tanpa foto + badge hitung `$noPhotoCount`.

### Update gelombang 160–164
- **Bukti titip + kwitansi settle + cetak massal** — di `/consignments`: tombol 🧾 Bukti Titip / 🧾 Kwitansi per baris + centang → cetak massal (maks 50 + page-break, tanpa route baru; foto settle opsional `[BUKTI:path]` di kas keluar).
- **Bukti beli HP bekas + cetak massal** — di `/secondhand`: tombol 🖨 Bukti per baris (No. SH-…, IMEI, grade, foto, TTD) + centang → cetak massal (maks 50 + page-break, tanpa route baru).
- **Bukti tukar tambah + cetak massal** — di `/tradeins`: area cetak per baris (No. TT-…, HP lama + IMEI + appraisal, foto `[FOTO:]`, TTD) + centang → cetak massal (maks 50 + page-break, tanpa route baru).
- **Bukti retur per baris** — di `/sales/{sale}`: tombol 🖨️ Cetak per baris retur/refund (No. RTR-…, nota asal, alasan, jumlah kembali + TTD, tanpa route baru).
- **Katalog harga cetak** — di `/products`: tombol 📖 Katalog Harga (produk aktif + filter & halaman ini, 20 baris/halaman, tanpa route baru).
- **Bantuan bisa cetak + changelog** — di `/help` (route `help`): tombol 🖨️ Cetak (area konten saja, tanpa Daftar Isi) + section changelog v1.0–v6.1.

### Update gelombang 165–169
- **Void 1-klik + TTD & foto retur** — `POST /returns` (form `?Void` di `GET /sales`): void hanya hari ini + milik kasir login + full-amount; retur/refund ber-uang-kembali (`amount>0`) wajib TTD canvas (`[SIG:]`) + foto bukti wajib bila nominal efektif >Rp2jt; bukti cetak per baris di `GET /sales/{sale}`.
- **TTD persetujuan cicilan/DP di POS** — `POST /pos`: cicilan/DP wajib TTD canvas persetujuan (`[SIG:]` di `sales.note`), tanpa migration; tampil di nota/invoice + thermal.
- **Ekspor CSV penjualan** — `GET /sales?export=csv` (ringkas) + `?export=items` (rincian per item: IMEI/qty/profit), hormati filter + toko, `;` + BOM, maks 5000.
- **Ekspor CSV hutang/pelanggan/garansi/pembelian** — `GET /debts?export=csv`, `/customers?export=csv`, `/warranties?export=csv`, `/purchases?export=csv` (hormati filter + toko, `;` + BOM, maks 5000).
- **Ekspor CSV kas/unit/service/teknisi/produk/promo** — `GET /cash?export=csv`, `/cash/flow?export=csv` (harian/bulanan + per kategori), `/units?export=csv`, `/services?export=csv`, `/services/report?export=csv`, `/technicians/report?export=csv`, `/products?export=csv`, `/promos/{promo}?export=csv` (pemakaian).
- **Ekspor CSV valuasi & deadstock** — `GET /stocks/valuation?export=csv`, `/stocks/deadstock?export=csv` (hormati filter, `;` + BOM).
- **Gate kas/bukti Rp10jt diperluas** — `POST /consignments/{consignment}/settle`, `POST /debts/bulk-pay` + `PUT /debts/{debt}`, `POST /purchases/{purchase}/pay`, `POST /technicians/{id}/pay` + `POST /technicians/pay-all`: kas keluar total >Rp10jt hanya owner/admin/superadmin + wajib foto bukti.

### Update gelombang 170–174
- **Favorit 30 hari 1-klik** — di `GET /pos` (tanpa route baru): kartu "Favorit 30 Hari Terakhir" (6 produk terlaris toko 30 hari) tambah ke keranjang 1 klik.
- **Hapus sparepart satuan** — `DELETE /services/spareparts/{sparepart}`: koreksi item salah input per baris di detail tiket (tanpa ubah status).
- **Printer default 1-klik** — `POST /settings/printers/{id}/default` di `/settings/printers`: tandai printer LAN/WiFi utama untuk kirim nota.
- **TOP + varians laba rugi** — di `GET /reports/profit` (tanpa route baru): Top 10 Produk (profit), Top Kasir Bulan Ini, profit per kategori + margin %, rugi per kategori pengeluaran, perbandingan omzet/laba/transaksi antar toko.

### Update gelombang 175–179
- **Pusat pengingat** — `GET /reminders` (Master → 🔔 Pengingat, owner/admin): agregat read-only 17 seksi (hutang lewat tempo, tiket terlambat >3 hari, siap diambil, garansi H-7/H-30/kedaluwarsa, booking H-1/menunggu, promo, opname >30 hari, unit yatim, stok menipis, WA gagal) + **Abaikan** per baris (localStorage `reminders_ignored_v1`) + **Cetak daftar** (area list saja, tanpa kirim WA massal — tindak lanjut manual per baris).
- **Profil saya** — `GET /profile` + `PUT /profile` (avatar → Profil Saya, semua role login): ubah nama/telepon/password sendiri tanpa buka Master → Pengguna.

### Update gelombang 180–184
- **Matriks gate kas & bukti** — satu acuan: `POST /cash` keluar >Rp10jt, `POST /returns` >Rp2jt, `POST /consignments/{consignment}/settle`, `POST /debts/bulk-pay` + `PUT /debts/{debt}`, `POST /purchases/{purchase}/pay`, `POST /technicians/{id}/pay` + `POST /technicians/pay-all` — semua kas keluar >Rp10jt hanya owner/admin/superadmin + wajib foto bukti; retur ber-uang-kembali wajib TTD `[SIG:]` (+ foto bila >Rp2jt); `POST /expenses` >Rp1jt & `POST /purchases` >Rp5jt wajib foto nota.

### Update gelombang 185–189
- **Pencarian global** — kolom search di navbar (`GET /search?q=`): hasil dikelompokkan (penjualan by invoice, service by tiket/IMEI, pelanggan by nama/HP, produk by nama/SKU global, unit by IMEI/serial), maks 5 per kelompok, scope toko aktif (produk global) + tombol *Lihat Semua* ke index masing-masing.

### Update gelombang 190–194
- **Audit pencarian** — `?q`/`?search` konsisten di semua index (normalisasi HP 08↔628 di service/pelanggan).
- **Preset tanggal** — tombol Hari ini / 7 Hari / Bulan ini di kas, penjualan, service report, laba rugi.
- **Filter tanggal tiket** — `GET /services?from=&to=` (ikut export CSV).
- **Filter bulan booking** — `GET /bookings?bulan=` (ikut export).
- **Filter periode garansi** — `GET /warranties?from=&to=` (ikut export).
- **Filter bulan pembelian** — `GET /purchases?bulan=` (ikut kedua export).

### Update gelombang 196–199
- **Shortcut `/`** — fokus ke pencarian navbar + badge versi `v{APP_VERSION}` di footer.
- **Import unit IMEI** — `GET /units/template` + `POST /units/import` (IMEI wajib unik; produk/supplier tak dikenal ditolak).
- **Import saldo awal** — `GET /debts/template` + `POST /debts/import` (tanpa mutasi kas; duplikat dilewati).
- **Import pengeluaran** — `GET /expenses/template` + `POST /expenses/import` (tiap baris = expense + kas keluar; gate >Rp10jt).
- **Import pembelian** — `GET /purchases/template` + `POST /purchases/import` (`items` = `SKU:qty:harga;…`; efek = store manual).

### Update gelombang 205–209
- **Lonceng notifikasi** — `GET /notifications/count` polling 60 dtk (siap_diambil + overdue + booking menunggu, scope toko; badge saja tanpa bunyi).
- **Ticker promo TV** — baris promo aktif berjalan di `/display` (ikut filter toko).
- **Banner mendesak** — badge ⚡ + baris merah untuk tiket prioritas mendesak di `/services`.
- **Posisi antrean lacak** — "N tiket di depan Anda" di `/track` (tiket aktif lebih tua, toko sama).
- **Ajakan servis berkala** — digest mingguan WA ke pelanggan dormant >6 bulan (maks 50/toko, cooldown 90 hari).

### Update gelombang 200–204
- **Jadwal ulang booking massal** — `POST /bookings/reschedule-selected` (maks 50, hanya `menunggu`/`dikonfirmasi` tanpa tiket; isi tanggal baru ATAU geser +N hari 1–90, baris bertiket/status final dilewati + dilaporkan).
- **Geser jatuh tempo massal** — `POST /debts/bulk-due-date` (maks 50 hutang/piutang belum lunas; tanggal baru menang atas +N hari 1–90, baris lunas dilewati + dilaporkan).
- **Duplikat promo massal** — `POST /promos/bulk-duplicate` (salin terpilih di toko asal, kode `KODE-COPY` unik, tanggal dikosongkan, nonaktif) + `POST /promos/bulk-duplicate-all` (maks 20 ke satu toko tujuan, kode `KODE-KODETOKO`, duplikat kode di toko tujuan dilewati).
- **Geser estimasi service massal** — `POST /services/estimasi-selected` (maks 50 tiket aktif; mode geser ±1–30 hari ATAU set tanggal, tiket `diserahkan`/`batal` dilewati + log per baris).
- **Perpanjang garansi massal** — `POST /warranties/extend-selected` (maks 50 garansi aktif; tambah N hari 1–365 default 30 dari maks(hari ini, akhir lama), non-aktif/NULL dilewati + dilaporkan).

### Update gelombang 206–209
- **Saldo berjalan kas** — di `/cash` (filter `?akun=` + kolom Saldo Akun berjalan per baris, ikut `?export=csv` kolom `saldo_berjalan`): dihitung dari seluruh riwayat per akun agar posisi saldo akurat walau filter aktif.
- **Rincian per akun di arus kas** — di `/cash/flow` mode bulan (`?bulan=`) & tahun (`?tahun=`) tabel Rincian per Akun (masuk/keluar/net + sorot ★ Terbesar), ikut `?export=csv`.
- **Kelola akun kas** — di `/cash`: `POST /cash/accounts` tambah akun + saldo awal opsional (terjurnal otomatis kas MASUK `modal` "Saldo awal …") + `PUT /cash/accounts/{account}` ubah nama/set default.

### Update gelombang 210–214
- **Laci kas BT/USB/LAN + auto** — tombol 🗄️ *Buka Laci (BT/USB)* di struk (`/pos/print/{sale}`) & nota service (`/services/{service}/print`) kirim pulsa ESC `p` 5 byte tanpa cetak (laci wajib ke port RJ11 printer); `POST /settings/printers/{id}/drawer` = Test Buka Laci LAN per printer; centang *Buka laci otomatis setelah bayar tunai* di `/settings/printers` (drawer-kick via BT/USB terakhir dipakai, dilewati diam-diam bila belum connect).

### Update gelombang 216–219
- **Perbaiki massal aman 1-klik** — `POST /health/fix-all-safe` (tombol 🛠️ *Perbaiki Semua yang Aman* di `/health`): jalankan 4 aksi aman berurutan (unit terjual→terjual, unit yatim→tersedia, komisi batal dihapus, garansi kedaluwarsa ditutup), yang gagal dilewati + dilaporkan, stok/sale/kas/hutang tetap manual.
- **Verifikasi backup + auto-verify** — `GET /settings/backup/{file}/verify` (tombol *Verifikasi* per baris di `/settings/backup`, read-only: SQL = header mysqldump + hitung CREATE TABLE/INSERT, JSON = valid + hitung kunci); tiap `POST /settings/backup` & `backup:weekly` otomatis verifikasi dan tampilkan label SEHAT/RUSAK di flash + digest.
- **Demo ON/OFF 1-klik** — `POST /settings/backup/demo-seed` (tombol 🧪 *Muat Data Demo*, idempoten: ditolak bila `DEMO-*` sudah ada) + `POST /settings/backup/demo-clean` (tombol 🗑 *Bersihkan Demo*, konfirmasi ganda checkbox + ketik `HAPUS DEMO`, hapus semua `DEMO-*` + flag `demo_seeded`) di `/settings/backup`; artisan/tinker tetap tersedia sebagai jalur manual.

### Update gelombang 125-129
- **Perjanjian cicilan**: `/sales/{sale}/agreement` — surat perjanjian kredit per transaksi cicilan/DP (tenor, angsuran, sisa + TTD); **massal** `/sales/agreements/bulk?ids[]=` (maks 20, non-cicilan dilewati).
- **Surat jalan mutasi**: `/stocks/mutations/surat-jalan?batch=TRM-…` — dokumen pengantar tiap batch transfer antar toko (rincian SKU/qty + daftar IMEI).
- **Surat hutang satuan**: `/debts/{debt}/statement` — cetak tagihan per hutang/piutang (versi massal tetap `/debts/statements`).
- **Log gagal login**: `/activity/log?date=YYYY-MM-DD` — section *auth-fail* 50 terakhir (email disamarkan + IP + sebab) di bawah log aktivitas.
- **Retensi log**: `php artisan backup:weekly` — selain backup `auto-*` (4 file), hapus `storage/logs/activity-*.log` berumur >90 hari.
- **Ulangi transaksi/pengeluaran**: tombol 🔁 di detail sale (prefill POS, unit terjual dilewati + kasir pilih unit baru) & di riwayat expense (prefill form + akun kas); note/ucapan ikut terbawa.
- **Pajak & kembalian di struk**: baris Pajak bila >0 (browser/WA/thermal/invoice A4); baris KEMBALIAN bila bayar > total.
- **Ucapan kado**: prefix `[UCAPAN]` — tampil besar di struk hadiah `?gift=1`, ikut thermal Bluetooth/USB/LAN & WA; tidak hilang saat tahan→lanjut struk.
- **Keranjang → bundling 1-klik**: kartu "Sering Dibeli Bersamaan" di laba rugi + tombol buat promo bundling ter-prefill di daftar promo.
- **Sorot terlambat**: baris merah + badge "Terlambat N hari" di daftar tiket (definisi = laporan SLA).

### Display TV antrean + suara
- `/display` (publik, tanpa login, `?store=` filter toko) — nomor tiket + status + estimasi; auto-refresh 30 detik; **chime Web Audio 3x + banner** saat jumlah *siap diambil* bertambah (ada tombol tes bunyi). Tanpa data sensitif (tanpa nama/HP/biaya/IMEI/teknisi).

### Tampilan, suara & offline
- **Mode gelap/terang**: tombol 🌙/☀️ di navbar (`localStorage servicehp-theme`, hormati preferensi OS saat pertama).
- **Mode kasir**: tombol di POS (`body.kasir-mode`) — sembunyikan header, perbesar scan & tombol bayar untuk layar sentuh.
- **Beep scan**: bunyi Web Audio tiap item masuk + nada sukses/gagal bayar; tombol 🔊/🔇 (ingat pilihan di `localStorage pos_beep_muted`).
- **PWA offline** (`public/sw.js`): halaman `/ /login /track /booking` di-cache; navigasi network-first → cache → `/offline.html`; hanya GET yang di-cache (login/transaksi selalu ke server); ada prompt update saat versi baru.

### Master & Pengaturan
- `/stores` — toko/cabang; `/stores/monitor` (owner/superadmin) — **monitoring pusat**: omzet, transaksi, service aktif, stok kritis per toko.
- `/customers` — CRM + riwayat; `POST /customers/merge` — **merge pelanggan duplikat** (pindahkan transaksi/service ke induk).
- `/suppliers`, `/promos` (diskon %, nominal, tukar tambah — otomatis disarankan di POS), `/users` (role superadmin/owner/kasir/teknisi).
- `/settings/printers` — printer universal: browser (semua printer) + Bluetooth/USB thermal 58/80mm + LAN/WiFi (daftar IP/port, test, kirim nota langsung); branding struk (nama toko, alamat, footer) via `POST /settings/printers/branding`.
- `/settings/wa` — gateway + template notif (placeholder `{nama} {tiket} {status} {total} {sisa} {jadwal} {device} {track}`), test kirim, retry log (`POST /settings/wa/logs/{id}/retry`).
- `/settings/backup` — **backup data** (superadmin/owner): mysqldump bila tersedia, fallback JSON; unduh & hapus file (lihat "Backup & restore").
- `/health` — **kesehatan data** (read-only + 4 aksi aman: tandai unit terjual/tersedia, hapus komisi yatim, tandai garansi kedaluwarsa) — 8 cek: unit terjual bermasalah, stok negatif, lompatan nomor invoice/kas/hutang, komisi yatim, unit service yatim, garansi kedaluwarsa.
- `/setup` — **checklist penyiapan toko** (progres %) — wajib dicek tiap buka toko baru.
- `/activity` — **log aktivitas** pengguna; `/help` — bantuan in-app.

## WhatsApp
Isi Gateway URL + token di `/settings/wa` (Fonnte/Wablas/generik POST `target/phone+message`, header `Authorization`). Tanpa gateway, pesan dicatat di `wa_logs` (status antri) + log Laravel. Template editable per kejadian (service, penjualan, booking, digest) dengan placeholder `{nama} {tiket} {status} {total}` (+ `{sisa} {jadwal} {device} {track}`).

## Digest otomatis (harian + mingguan)
- Perintah: `php artisan digest:daily [--date=Y-m-d] [--store=id/kode] [--dry-run]` — rekap **kemarin** per toko (omzet + transaksi + service) ke WA owner pertama tiap toko / telepon toko. `--dry-run` = tampilkan calon penerima tanpa kirim. Default `--date` = kemarin.
- `php artisan digest:weekly [--from= --to=] [--store=] [--dry-run]` — rekap 7 hari (default 7 hari ke belakang s/d kemarin): omzet, service, **ringkasan 8 cek kesehatan**, booking 7 hari ke depan.
- Isi pesan harian: omzet, service, **pengingat booking H-1**, **hutang jatuh tempo ≤7 hari**, **ringkasan promo aktif**, **eskalasi tiket mendesak**, pembatalan basi.
- Jadwal: `routes/console.php` → `digest:daily` 21:00, `digest:weekly` Senin 07:00, `backup:weekly` Senin 02:00 (lihat bawah).
- Kirim manual: tombol **📊 Kirim Digest Sekarang** di `/sales/closing`, atau `POST /sales/closing/digest`.
- Kirim rekap tutup kasir via WA: `POST /sales/closing/wa`.
- Tanpa gateway, digest berstatus *antri* di log WA.

## Scheduler di Windows (wajib agar digest 21:00 + backup Senin jalan)
0. Wajib set `APP_TIMEZONE=Asia/Jakarta` di `.env` (tanpa ini jadwal jalan ikut UTC = jam 4 pagi WIB). Jangan `php artisan config:cache` setelah ubah `.env` tanpa `config:clear` dulu.
1. Buka **Task Scheduler → Create Basic Task** → trigger **Daily**, ulangi **tiap 1 menit** (pada tab Trigger → Advanced → Repeat task every 1 minute).
2. Action: `php D:\project laravel\servicehp\artisan schedule:run` (Start in: `D:\project laravel\servicehp`).
3. Alternatif tanpa scheduler: jalankan manual tiap malam `php artisan digest:daily`.

## Backup & restore
- **Backup**: buka `/settings/backup` (superadmin/owner) → **Buat Backup Sekarang**. Bila `mysqldump` ditemukan (PATH / Laragon `C:\laragon\bin\mysql\*\bin\` / XAMPP `C:\xampp\mysql\bin\`) hasilnya `.sql` penuh (`--routines --single-transaction`); bila tidak, fallback `.json` berisi tabel penting (`stores, products, sales, service_tickets, customers, cash_transactions`). File di `storage/app/backups/backup-Ymd-His.{sql,json}` — bisa diunduh / dihapus dari halaman yang sama (`GET /settings/backup/{file}`, `DELETE /settings/backup/{file}`).
- **Backup otomatis mingguan**: `php artisan backup:weekly [--keep=4]` → `storage/app/backups/auto-*`, retensi default 4 file (jadwal Senin 02:00).
- **ZIP CSV**: tombol **Unduh ZIP CSV** (`GET /settings/backup/csv`) — arsip semua-toko (penjualan, item, service, produk, pelanggan, kas, hutang) untuk audit/Excel.
- **Restore SQL**: via CLI MySQL, contoh Laragon: `mysql -u root -p nama_db < storage\app\backups\backup-XXXX.sql` (atau phpMyAdmin → Import). **Hentikan app / backup dulu** sebelum restore agar tidak menimpa data baru.
- **Restore JSON (fallback)**: hanya arsip baca — tidak ada tombol restore otomatis; impor manual per tabel bila perlu.

## Keamanan
- Login dibatasi **10x/menit per IP** (`throttle:10,1`, 429 bila lebih) — pesan gagal generik agar tak membocorkan email terdaftar.
- **Guard stok**: hapus transaksi yang sudah punya retur **diblokir** (stok sudah dikembalikan via retur — hapus akan menggandakan stok); hapus = kembalikan stok/unit + bersihkan kas & hutang terkait.
- Tidak ada registrasi mandiri — akun baru hanya via owner/superadmin (`/users`); selalu logout setelah shift.

## Format CSV import produk
Menu *Produk & HP → Import* (`POST /products/import`, maks 2 MB, `.csv`/`.txt`). Header **wajib baris pertama** (huruf kecil, dipisah koma `,`):

```csv
sku,nama,type,brand,category,harga_beli,harga_jual,min_stock
```

- `nama` wajib; `type` wajib salah satu dari: `hp_baru, hp_bekas, refurbished, aksesoris, sparepart, pulsa, paket_data, lain` (default `lain`).
- `brand`/`category` dibuat otomatis bila belum ada. `sku` unik — baris dengan SKU sama akan **update**, SKU kosong → dibuatkan `SKU-YYYYMMDD-XXXX`.
- `harga_beli`/`harga_jual` angka (default 0), `min_stock` bilangan bulat (default 5).
- Contoh: `HP001,Xiaomi Redmi 13,hp_baru,Xiaomi,HP,1500000,1750000,2`
- Catatan: file dari tombol **Export** memakai delimiter `;` (Excel Indonesia) — untuk import, simpan ulang dengan koma `,` atau ganti `;` → `,`.

## PWA & Printer
- `public/manifest.webmanifest` + `public/sw.js` — installable dari HP/tablet/PC; halaman `/ /login /track /booking` tetap bisa dibuka **offline** (`/offline.html` bila belum di-cache); prompt update otomatis saat versi baru.
- Struk: `printStruk('struk-58')` (window print, kertas 58/80mm) + tombol Web Bluetooth untuk printer thermal (Chrome Android).
