@extends('layouts.tabler')
@section('title','Bantuan')
@section('content')
<div class="page-header d-print-none">
  <div class="row align-items-center">
    <div class="col">
      <div class="page-pretitle">In-App Help</div>
      <h2 class="page-title">❓ Bantuan</h2>
      <div class="text-secondary">Panduan singkat operasional harian ServiceHP.</div>
    </div>
    <div class="col-auto ms-auto">
      <button
        type="button"
        class="btn btn-outline-primary"
        onclick="window.print()"
      >
        🖨️ Cetak
      </button>
    </div>
  </div>
</div>

<div class="row row-cards mt-2">
<div class="col-lg-8">

<div class="card mb-3" id="pos"><div class="card-header"><h3 class="card-title">🧾 POS / Kasir</h3></div><div class="card-body">
<ol class="mb-0">
<li>Buka <b>POS / Kasir</b>, pilih toko aktif di kanan atas.</li>
<li>Scan barcode atau ketik SKU/IMEI lalu <b>Enter</b> untuk tambah item. Stok menipis & habis ditandai otomatis.</li>
<li>Atur qty, promo/voucher bila ada (ada <b>auto-saran promo</b> — atau isi kode manual), pilih metode bayar (tunai/transfer/QRIS/split/cicilan/DP).</li>
<li><b>Split payment</b>: centang <i>Split payment</i> → isi Tunai + Transfer/QRIS → total harus <b>pas</b> (selisih &gt; Rp 1 ditolak).</li>
<li><b>Tahan struk (F9)</b>: isi <i>Nama utk ditahan</i> → klik <b>⏸️ Tahan</b> → lanjutkan/hapus dari panel <i>Struk Ditahan</i>.</li>
<li>Klik <b>Bayar</b> → struk tampil → cetak / kirim WA ke pelanggan.</li>
<li>Shortcut: <b>F2</b> fokus scan, <b>F4</b> bayar tunai, <b>F9</b> tahan struk, <b>Esc</b> reset.</li>
<li>Retur: menu <b>Retur & Refund</b>, pilih transaksi asal. Tukar tambah: menu <b>Tukar Tambah</b> (juga bisa inline di POS).</li>
</ol>
</div></div>

<div class="card mb-3" id="service"><div class="card-header"><h3 class="card-title">🔧 Service</h3></div><div class="card-body">
<ol class="mb-0">
<li><b>Terima HP Baru</b>: isi pelanggan, tipe HP, IMEI, keluhan, kondisi + foto.</li>
<li>Teknisi isi <b>diagnosa + estimasi biaya</b> → status <i>menunggu_persetujuan</i>.</li>
<li>Minta persetujuan pelanggan via <b>WA</b> (tombol kirim di detail tiket).</li>
<li>Kerjakan → tambah sparepart → <b>QC</b> → <i>siap_diambil</i> (notif WA otomatis/manual).</li>
<li>Serah terima: pastikan pelunasan, cetak nota + garansi. <b>Checklist QC</b> (nyala, sinyal, kamera, charging, tombol, speaker) + <b>checklist serah terima & tanda tangan</b> pelanggan diisi di detail tiket.</li>
<li>Tiket <b>mendesak</b> bisa diprioritaskan (<i>rendah/normal/mendesak</i>, sortir <code>?sort=priority</code>); ETA otomatis mengikuti prioritas.</li>
<li>Tiket <b>terlambat</b> = status aktif & dibuat &gt;3 hari → muncul di widget <i>Perlu Perhatian</i>.</li>
<li><b>Laporan service</b>: menu <i>Service → Laporan Service</i> (periode + toko). <b>Komisi teknisi</b>: menu <i>Teknisi & Komisi → Laporan</i>, bayar via tombol <i>Bayar</i>.</li>
<li>Pelanggan bisa lacak sendiri di <b>/track</b> dan booking online di <b>/booking</b>.</li>
</ol>
</div></div>

<div class="card mb-3" id="inventory"><div class="card-header"><h3 class="card-title">📦 Inventory & IMEI</h3></div><div class="card-body">
<ol class="mb-0">
<li><b>Produk & HP</b>: tambah produk + foto; barcode otomatis (siap scanner USB).</li>
<li><b>Unit / IMEI</b>: catat tiap unit HP (masuk/terjual/retur); klik unit untuk <b>timeline</b> perjalanannya.</li>
<li><b>Stok Opname</b>: hitung fisik → selisih tercatat otomatis sebagai mutasi.</li>
<li><b>Mutasi & Transfer</b>: penyesuaian stok dan <b>transfer antar toko</b>.</li>
</ol>
</div></div>

<div class="card mb-3" id="belanja"><div class="card-header"><h3 class="card-title">🛒 Saran Belanja (Reorder)</h3></div><div class="card-body">
<ol class="mb-0">
<li>Buka <i>Produk & HP → <b>Saran Belanja</b></i> (<code>/products/reorder</code>).</li>
<li>Daftar berisi produk dengan stok ≤ minimum + <b>usulan qty</b> (buffer 7 hari dari rata-rata penjualan 30 hari).</li>
<li>Belanja ke supplier via menu <b>Pembelian Supplier</b>; tandai <i>Diterima</i> agar stok bertambah.</li>
</ol>
</div></div>

<div class="card mb-3" id="harga-massal"><div class="card-header"><h3 class="card-title">💲 Ubah Harga Massal</h3></div><div class="card-body">
<ol class="mb-0">
<li>Buka <i>Produk & HP → <b>Harga Massal</b></i> (<code>/products/bulk</code>).</li>
<li>Filter kategori/brand/type + pencarian, lalu ubah harga beli/jual per baris atau sekaligus (naik/turun % atau nominal).</li>
<li>Klik <b>Simpan</b> — perubahan langsung berlaku di POS.</li>
</ol>
</div></div>

<div class="card mb-3" id="keuangan"><div class="card-header"><h3 class="card-title">💼 Keuangan</h3></div><div class="card-body">
<ol class="mb-0">
<li><b>Kas Masuk/Keluar</b>: catat per akun kas; <b>Transfer</b> untuk pindah saldo antar akun.</li>
<li><b>Hutang & Piutang</b>: catat → lunasi bertahap via tombol <i>Bayar</i> (bisa ubah jatuh tempo); <b>bayar massal</b> & <b>cetak tagihan massal</b> tersedia di menu Hutang. Pelunasan cicilan/DP penjualan ada di detail transaksi (<i>Riwayat Transaksi → Bayar</i>, kwitansi <code>KWT-…</code> + tanda tangan).</li>
<li><b>Laba Rugi</b>: pilih toko + periode → unduh CSV bila perlu. Versi lengkap memuat <b>neraca, ROI, estimasi PPh UMKM 0,5%, MoM, YoY + ringkasan narasi otomatis</b>.</li>
<li><b>Budget & target</b>: <i>Keuangan → Pengeluaran</i> = budget per kategori per bulan + tampilan tahunan (<code>?tahun=</code>); target omzet toko diisi di <i>Master → Toko</i> → Dashboard menampilkan <b>kejar target harian</b> + progres tahunan; target teknisi di <i>Teknisi & Komisi</i>.</li>
<li><b>Valuasi & deadstock</b>: <i>Stok → Valuasi / Deadstock</i> untuk nilai modal tertanam & stok macet + saran tindak lanjut.</li>
<li><b>Merge pelanggan</b>: di <i>Pelanggan (CRM)</i>, gabungkan data duplikat (transaksi & service pindah ke data induk). Merge serupa ada untuk <b>produk, supplier, brand, kategori</b> (masing-masing ada peringatan duplikat di halamannya).</li>
</ol>
</div></div>

<div class="card mb-3" id="cetak-massal"><div class="card-header"><h3 class="card-title">🖨️ Cetak Massal & Dokumen</h3></div><div class="card-body">
<ol class="mb-0">
<li><b>Invoice A4</b>: dari detail transaksi → <i>Invoice</i> (<code>/sales/{sale}/invoice</code>, logo + branding). <b>Massal</b>: centang di <i>Riwayat Transaksi</i> → <i>Cetak Massal</i> (≤50 invoice, <code>/sales/invoices/bulk</code>).</li>
<li><b>PO massal</b>: centang di <i>Pembelian Supplier</i> → <i>Cetak Massal</i> (<code>/purchases/bulk-print</code>). <b>Voucher kas massal</b>: centang mutasi di <i>Kas</i> → <i>Cetak Voucher</i> (≤50, <code>/cash/vouchers</code>).</li>
<li><b>Tiket service massal</b> (<code>/services/bulk-tickets</code>), <b>tagihan hutang massal</b> (<code>/debts/statements</code>), <b>kartu garansi massal</b> (<code>/warranties/cards</code>), <b>kartu member massal</b> (<code>/customers/member-cards</code>), <b>lembar QR promo</b> (<code>/promos/qr-sheet</code>, tempel di etalase).</li>
<li><b>Label barcode rak</b>: buka detail produk → isi <i>Jumlah label</i> → <i>Cetak Label</i> (grid 3 kolom). <b>Struk hadiah</b> (tanpa harga): tombol 🎁 di halaman struk (<code>?gift=1</code>).</li>
<li><b>vCard</b>: tombol unduh kontak di detail pelanggan / supplier / teknisi (file <code>.vcf</code>, langsung tersimpan ke HP).</li>
</ol>
</div></div>

<div class="card mb-3" id="crm"><div class="card-header"><h3 class="card-title">👥 CRM Lanjutan</h3></div><div class="card-body">
<ol class="mb-0">
<li><b>Kartu repeat</b> di <i>Pelanggan</i>: % pelanggan belanja ulang 30 hari. <b>Skor RFM</b> di detail pelanggan: jumlah belanja, hari sejak belanja terakhir, rata-rata — otomatis flag <i>dormant</i> bila &gt;90 hari.</li>
<li><b>Siaran WA</b>: <i>Pelanggan → Siaran</i> — kirim pesan massal ke pelanggan terpilih. <b>Tagihan via WA</b>: tombol di detail pelanggan / tiap hutang.</li>
<li><b>Pembelian terakhir + histori</b> tampil di detail pelanggan untuk upselling.</li>
</ol>
</div></div>

<div class="card mb-3" id="promo"><div class="card-header"><h3 class="card-title">🏷️ Promo Engine</h3></div><div class="card-body">
<ol class="mb-0">
<li>Tipe: <b>diskon %, nominal, bundling, voucher, member</b> — nilai persen dicap 100% + validasi per tipe.</li>
<li><b>Hitung mundur</b> tampil otomatis di daftar promo; <b>QR per promo</b> + lembar QR massal untuk etalase.</li>
<li><b>Duplikat</b> promo/produk via tombol <i>Duplikat</i>; <b>matikan massal</b> promo kedaluwarsa (<i>Promo → Matikan Massal</i>).</li>
</ol>
</div></div>

<div class="card mb-3" id="baru-96-99"><div class="card-header"><h3 class="card-title">✨ Baru 96–99</h3></div><div class="card-body">
<ol class="mb-0">
<li><b>Slip komisi massal</b>: <i>Teknisi & Komisi</i> → pilih teknisi + bulan → <i>Slip Massal</i> (<code>/technicians/slips/bulk</code>).</li>
<li><b>Digest pagi 07:00</b>: kesiapan hari ini per toko via WA owner (<code>php artisan digest:morning</code>); harian 21:00 + mingguan Senin 07:00 tetap jalan.</li>
<li><b>Jejak pindah lokasi</b>: opname yang hanya ganti lokasi (qty tetap) tercatat di <i>Mutasi</i> (<code>/stocks/mutations</code>, qty 0) + riwayat produk.</li>
<li><b>Promo bulk on/off</b>: di <i>Promo</i> centang promo terpilih → <i>Aktifkan Massal</i> (<code>/promos/bulk-on</code>) / <i>Matikan Massal</i> (<code>/promos/bulk-off</code>).</li>
<li><b>Audit diskon</b>: <i>Tutup Kasir</i> (<code>/sales/closing</code>) — rasio diskon÷omzet per kasir: PANTAU &gt;15%, AWAS &gt;25%.</li>
<li><b>Alert jual rugi</b>: <i>Produk & HP</i> → <i>Filter hanya jual rugi</i> (<code>/products?rugi=1</code>, jual &lt; beli).</li>
<li><b>Total diskon laporan</b>: <i>Laba Rugi</i> (<code>/reports/profit</code>) — total diskon = header + per item.</li>
<li><b>Tak-pernah-laku</b>: <i>Stok → Deadstock</i> → centang <i>hanya tak-pernah-laku</i> (<code>/stocks/deadstock?never_sold=1</code>).</li>
</ol>
</div></div>

<div class="card mb-3" id="baru-100-119"><div class="card-header"><h3 class="card-title">✨ Baru 100–119</h3></div><div class="card-body">
<ol class="mb-0">
<li><b>Pindah toko massal</b>: centang tiket di <i>Service</i> → <i>Pindah Toko</i> (<code>/services/transfer-selected</code>, satuan ada di detail tiket); sama untuk <i>Booking</i> (<code>/bookings/transfer-selected</code>) dan <i>Pelanggan</i> (<code>/customers/move-store</code>).</li>
<li><b>Aksi tiket massal</b>: set prioritas sekaligus (<code>/services/priority-selected</code>), ingatkan ambil untuk tiket <i>siap_diambil</i> (<code>/services/remind-pickup-selected</code>), ingatkan teknisi (<code>/services/remind-technician-selected</code>).</li>
<li><b>Aksi booking massal</b>: setujui / batalkan / ingatkan WA / pindah toko sekaligus (<code>/bookings/confirm-selected</code>, <code>/bookings/cancel-selected</code>, <code>/bookings/remind-selected</code>, <code>/bookings/transfer-selected</code>).</li>
<li><b>Gabung duplikat otomatis</b>: tombol <i>Gabung Otomatis</i> di halaman Pelanggan/Produk/Supplier/Merek/Kategori (<code>/customers/merge-exact</code> dkk.) — hanya data identik penuh yang digabung, sisanya dilaporkan & dilewati.</li>
<li><b>Produk</b>: set merek/kategori massal (<code>/products/bulk-assign</code>), ubah min-stok per produk (<code>/products/{product}/min-stock</code>), kirim daftar restock ke supplier via WA dari <i>Saran Belanja</i> (<code>/products/reorder/wa</code>).</li>
<li><b>Promo antar-toko</b>: tombol <i>Duplikat ke Semua Toko</i> di detail promo (<code>/promos/{promo}/duplicate-all</code>, kode jadi <code>KODE-KODETOKO</code>).</li>
<li><b>Pengguna massal</b>: ubah role / aktif-nonaktif sekaligus di <i>Master → Pengguna</i> (akun sendiri & superadmin terakhir dilindungi).</li>
<li><b>Pengingat WA massal</b>: hutang (<code>/debts/bulk-remind</code>) & garansi aktif (<code>/warranties/remind-selected</code>); ubah kategori pengeluaran massal (<code>/expenses/bulk-category</code>).</li>
<li><b>Kunci periode</b>: <i>Kas → Tutup Kasir/Handover</i> — owner bisa mengunci tanggal (transaksi lama ditolak) & membukanya lagi (<code>/cash/closing/lock</code>).</li>
<li><b>Log aktivitas</b>: <i>Dashboard → Aktivitas</i> (<code>/activity</code>) + arsip per tanggal (<code>/activity/log?date=</code>).</li>
<li><b>Otomatisasi digest</b> (tiap digest pagi/harian/mingguan): promo kedaluwarsa dinonaktifkan, garansi H-7 & H-30 diingatkan, booking basi dibatalkan otomatis, tiket lama dieskalasi — semua tercatat di pesan digest.</li>
</ol>
</div></div>

<div class="card mb-3" id="baru-120-124"><div class="card-header"><h3 class="card-title">✨ Baru 120–124</h3></div><div class="card-body">
<ol class="mb-0">
<li><b>Security sweep</b>: <i>Master → WhatsApp/Printer/Toko</i> hanya owner/admin; kirim nota printer dicek per-toko aktif; tombol komisi & aksi gudang disembunyikan bila tanpa hak; login/booking/lacak publik dibatasi rate (<code>POST /login</code>, <code>POST /booking</code>, <code>POST /track</code>).</li>
<li><b>Gate komisi</b>: bayar komisi satuan & semua (<code>/technicians/{id}/pay</code>, <code>/technicians/pay-all</code>) hanya owner/admin — teknisi tak bisa membayar komisi sendiri.</li>
<li><b>Blokir jual rugi</b>: di POS (<code>POST /pos</code>) kasir/teknisi ditolak bila harga &lt; modal (modal IMEI bila ada).</li>
<li><b>Cap diskon 30%</b>: diskon manual POS kasir/teknisi maks 30% × subtotal sebelum promo (promo & diskon per-baris tak dicap).</li>
<li><b>Bukti wajib</b>: pengeluaran &gt; Rp1jt (<code>POST /expenses</code>) & pembelian &gt; Rp5jt (<code>POST /purchases</code>) wajib foto nota; kas & cicilan dukung foto opsional.</li>
<li><b>Gate nominal besar</b>: kas keluar &gt; Rp10jt (<code>POST /cash</code>) & retur &gt; Rp2jt (<code>POST /returns</code>) hanya owner/admin.</li>
<li><b>Klaim massal</b>: centang garansi aktif → <i>Klaim Massal</i> (<code>/warranties/claim-selected</code>, maks 50).</li>
<li><b>Pindah toko massal</b>: service / booking / pelanggan (<code>/services/transfer-selected</code>, <code>/bookings/transfer-selected</code>, <code>/customers/move-store</code>).</li>
<li><b>Stok negatif</b>: <i>Kesehatan Data</i> (<code>/health</code>) hanya menampilkan stok minus — koreksi via <i>Stok Opname</i> (<code>/stocks/opname</code>).</li>
</ol>
</div></div>

<div class="card mb-3" id="baru-131-144"><div class="card-header"><h3 class="card-title">✨ Baru 131–144</h3></div><div class="card-body">
<ol class="mb-0">
<li><b>Min stok per toko</b>: override per toko di opname (kosong = ikut global) + set global massal; semua alert pakai nilai efektif.</li>
<li><b>Lokasi gudang</b>: kolom lokasi + filter + label rak + jejak pindah tercatat (qty 0) di Kartu Stok.</li>
<li><b>Arus kas tahunan & tren laba</b>: <code>/cash/flow?tahun=</code> (12 bulan + drill-down) dan grafik Jan–Des di laba rugi.</li>
<li><b>Salin target tahun depan</b>: 1 klik di Monitoring (idempoten, tanpa timpa).</li>
<li><b>Import/template semua master</b>: produk, pelanggan, supplier, promo, merek, kategori (delimiter auto, contoh cocok importer).</li>
<li><b>Promo massal</b>: on/off massal + perpanjang +30 hari (basis hari ini).</li>
<li><b>Backup lengkap</b>: ZIP ikut <code>settings/*.json</code> + tombol bersihkan cache + audit file yatim (<code>/settings/storage</code>).</li>
<li><b>Kartu & label massal</b>: kartu member/garansi, slip komisi, voucher kas, tagihan, invoice, PO, tiket, label rak/IMEI/lokasi — semua ≤50 + page-break.</li>
<li><b>Set teknisi massal</b>: rebalance tiket via <code>POST services/technician-selected</code> (log per baris).</li>
<li><b>Konversi booking + CSV</b>: rasio tiket-terbuat di <code>/bookings</code> + export <code>?export=csv</code>.</li>
<li><b>Raster thermal</b>: logo + TTD di struk LAN; self-test lapor byte + QR sampel.</li>
<li><b>Bukti & TTD lengkap</b>: nota/transfer/piutang/settle/komisi/supplier/pembelian + foto wajib (barang bekas, terima HP, QC, kas &gt;10jt, PO &gt;5jt).</li>
<li><b>Peta toko & vCard</b>: Buka Maps + salin link + share WA per toko; vCard pelanggan/supplier/teknisi.</li>
</ol>
</div></div>

<div class="card mb-3" id="baru-150-153"><div class="card-header"><h3 class="card-title">✨ Baru 150–153</h3></div><div class="card-body">
<ol class="mb-0">
<li><b>Raster gambar BT/USB</b>: tombol 📡 <i>BT Gambar</i> / 🔌 <i>USB Gambar</i> (<code>PrintHub.printImage</code>) di struk (<code>/pos/print/{sale}</code>) & nota service (<code>/services/{service}/print</code>) — cetak tampilan apa adanya sebagai gambar.</li>
<li><b>Self-test printer</b>: tombol <i>Test</i> di <i>Printer & Cetak</i> (<code>/settings/printers</code> → <code>POST /settings/printers/{id}/test</code>) — struk teks + logo + QR sampel, lapor byte + status logo.</li>
<li><b>Generator kode promo</b>: tombol 🎲 di <i>Tambah Promo</i> (<code>/promos/create</code>) — buat suffix acak 4–6 char dari prefix, masih bisa diedit.</li>
<li><b>Template ucapan kado</b>: dropdown ⚡ di <i>POS</i> (<code>/pos</code> → HBD / Terima Kasih / Lekas Sembuh / Sukses) mengisi kolom <i>Catatan / ucapan kado</i>, tampil besar di struk hadiah (<code>?gift=1</code>).</li>
<li><b>Nomor antrean walk-in</b>: tombol <i>Ambil Nomor</i> di TV (<code>POST /queue/take</code> → <code>/display?taken=Axxx</code>) — nomor A001… reset harian + slip siap cetak.</li>
<li><b>Panggil berikutnya TV</b>: tombol 📢 di <i>Layar TV</i> (<code>/display</code>) — panggil tiket <i>siap diambil</i> satu-per-satu + bunyi + banner (per perangkat).</li>
<li><b>Mode kasir/fullscreen</b>: tombol di <i>POS</i> (<code>/pos</code>) — tampilan sentuh besar + fullscreen, pilihan tersimpan otomatis.</li>
<li><b>Dark mode</b>: tombol 🌙/☀️ di navbar — tema tersimpan otomatis, ikut setting HP saat pertama, tanpa kedip saat pindah halaman.</li>
<li><b>Ekspor booking CSV</b>: tombol <i>Export CSV</i> di <i>Booking</i> (<code>/bookings?export=csv</code>) — ikut filter status/cari + toko.</li>
<li><b>Min stok per toko</b>: override per toko di opname (<code>POST /products/{product}/min-stock</code>, kosong = ikut global) + set massal; semua alert pakai nilai efektif.</li>
<li><b>Rata-rata nilai tiket</b>: kartu rata-rata di <i>Laporan Service</i> (<code>/services/report</code>) — avg per tiket + avg selisih aktual−estimasi + avg hari & jam tunggu per teknisi.</li>
</ol>
</div></div>

<div class="card mb-3" id="baru-155-159"><div class="card-header"><h3 class="card-title">✨ Baru 155–159</h3></div><div class="card-body">
<ol class="mb-0">
<li><b>Jadwal angsuran + cetak</b>: buka detail transaksi cicilan (<code>/sales/{sale}</code>) → kartu <i>Jadwal Angsuran</i> → <i>Cetak Jadwal</i>.</li>
<li><b>WA rekap + jadwal cicilan</b>: di detail pelanggan → <i>Kirim rekap via WA</i> (<code>/customers/{customer}/statement-wa</code>) — kini sertakan JT berikutnya per invoice cicilan.</li>
<li><b>Grafik komisi</b>: <i>Teknisi & Komisi → Laporan</i> (<code>/technicians/report?bulan=</code>) — batang per teknisi (menunggu vs dibayar).</li>
<li><b>Pengingat opname</b>: otomatis di digest harian (<code>/sales/closing/digest</code> / <code>digest:daily</code>) bila opname terakhir &gt;30 hari / belum pernah.</li>
<li><b>Struk WA otomatis</b>: di POS (<code>POST /pos</code>) centang <i>kirim struk via WA</i> sebelum Bayar — terkirim/antri otomatis, gagal tak batalkan transaksi.</li>
<li><b>Tanggal habis stok</b>: <i>Saran Belanja</i> (<code>/products/reorder</code>) — kolom <i>Tahan (hari)</i> + <i>Habis tgl</i> (&lt;7 hr = segera restock).</li>
<li><b>Filter tanpa foto</b>: <i>Produk & HP</i> → <i>📷 Tanpa foto</i> (<code>/products?no_photo=1</code>) — produk aktif tanpa foto + badge hitung.</li>
</ol>
</div></div>

<div class="card mb-3" id="baru-160-164"><div class="card-header"><h3 class="card-title">✨ Baru 160–164</h3></div><div class="card-body">
<ol class="mb-0">
<li><b>Bukti titip + kwitansi settle + massal</b>: di <i>Konsinyasi</i> (<code>/consignments</code>) — tombol 🧾 <i>Bukti Titip</i> / 🧾 <i>Kwitansi</i> per baris + centang → cetak massal (maks 50, tanpa route baru).</li>
<li><b>Bukti beli HP bekas + massal</b>: di <i>Beli HP Bekas</i> (<code>/secondhand</code>) — tombol 🖨 <i>Bukti</i> per baris (No. SH-…, IMEI, grade, foto, TTD) + centang → cetak massal (maks 50, tanpa route baru).</li>
<li><b>Bukti tukar tambah + massal</b>: di <i>Tukar Tambah</i> (<code>/tradeins</code>) — area cetak per baris (No. TT-…, HP lama + IMEI + appraisal, foto, TTD) + centang → cetak massal (maks 50, tanpa route baru).</li>
<li><b>Bukti retur per baris</b>: di detail transaksi (<code>/sales/{sale}</code>) — tombol 🖨️ <i>Cetak</i> per baris retur/refund (No. RTR-…, jumlah kembali + TTD, tanpa route baru).</li>
<li><b>Katalog harga</b>: di <i>Produk & HP</i> (<code>/products</code>) — tombol 📖 <i>Katalog Harga</i> (produk aktif + filter & halaman ini, 20 baris/halaman, tanpa route baru).</li>
<li><b>Bantuan bisa cetak + changelog</b>: halaman ini (<code>/help</code>) — tombol 🖨️ <i>Cetak</i> (konten saja) + section <i>changelog</i> v1.0–v6.1 di bawah.</li>
</ol>
</div></div>

<div class="card mb-3" id="baru-165-169"><div class="card-header"><h3 class="card-title">✨ Baru 165–169</h3></div><div class="card-body">
<ol class="mb-0">
<li><b>Void 1-klik + TTD & foto retur</b>: <code>POST /returns</code> (tombol Void di <code>/sales</code>) — void hanya hari ini + milik kasir; retur ber-uang-kembali wajib TTD canvas; &gt;Rp2jt wajib foto bukti; bukti cetak di <code>/sales/{sale}</code>.</li>
<li><b>TTD cicilan/DP di POS</b>: <code>POST /pos</code> — cicilan/DP wajib TTD persetujuan pelanggan, tersimpan <code>[SIG:]</code> + tampil di nota.</li>
<li><b>Ekspor CSV penjualan</b>: <code>/sales?export=csv</code> (ringkas) + <code>?export=items</code> (rincian per item + IMEI/profit) — ikut filter + toko.</li>
<li><b>Ekspor CSV hutang/pelanggan/garansi/beli</b>: <code>/debts?export=csv</code>, <code>/customers?export=csv</code>, <code>/warranties?export=csv</code>, <code>/purchases?export=csv</code> — ikut filter + toko.</li>
<li><b>Ekspor CSV kas/unit/service/teknisi/produk/promo</b>: <code>/cash?export=csv</code>, <code>/cash/flow?export=csv</code>, <code>/units?export=csv</code>, <code>/services?export=csv</code> + <code>/services/report?export=csv</code>, <code>/technicians/report?export=csv</code>, <code>/products?export=csv</code>, <code>/promos/{promo}?export=csv</code>.</li>
<li><b>Ekspor CSV valuasi & deadstock</b>: <code>/stocks/valuation?export=csv</code>, <code>/stocks/deadstock?export=csv</code> — ikut filter.</li>
<li><b>Gate Rp10jt diperluas</b>: <code>/consignments/{consignment}/settle</code>, <code>/debts/bulk-pay</code> + <code>/debts/{debt}</code>, <code>/purchases/{purchase}/pay</code>, <code>/technicians/{id}/pay</code> + <code>/technicians/pay-all</code> — keluar &gt;Rp10jt hanya owner/admin + wajib foto.</li>
</ol>
</div></div>

<div class="card mb-3" id="baru-170-174"><div class="card-header"><h3 class="card-title">✨ Baru 170–174</h3></div><div class="card-body">
<ol class="mb-0">
<li><b>Favorit 30 hari 1-klik</b>: di <i>POS</i> (<code>GET /pos</code>, tanpa route baru) — kartu <i>Favorit 30 Hari Terakhir</i> (6 produk terlaris toko) tambah ke keranjang 1 klik.</li>
<li><b>Hapus sparepart satuan</b>: tombol hapus per baris di detail tiket (<code>DELETE /services/spareparts/{sparepart}</code>) — koreksi salah input tanpa ubah status.</li>
<li><b>Printer default 1-klik</b>: di <i>Printer & Cetak</i> (<code>/settings/printers</code> → <code>POST /settings/printers/{id}/default</code>) — tandai printer LAN/WiFi utama.</li>
<li><b>TOP + varians laba rugi</b>: di <i>Laba Rugi</i> (<code>/reports/profit</code>, tanpa route baru) — Top 10 Produk (profit), Top Kasir Bulan Ini, profit per kategori + margin %, rugi per kategori pengeluaran, perbandingan antar toko.</li>
</ol>
</div></div>

<div class="card mb-3" id="baru-175-179"><div class="card-header"><h3 class="card-title">✨ Baru 175–179</h3></div><div class="card-body">
<ol class="mb-0">
<li><b>Pusat pengingat</b>: buka <i>Master → 🔔 Pengingat</i> (<code>/reminders</code>, owner/admin) — 17 seksi read-only (hutang lewat tempo, tiket terlambat, siap diambil, garansi, booking H-1, opname &gt;30 hari, dst.) → tombol <i>Abaikan</i> per baris (tersimpan di browser) + <i>🖨 Cetak daftar</i>; tindak lanjut manual per baris (tanpa kirim WA massal).</li>
<li><b>Profil saya</b>: klik avatar → <i>Profil Saya</i> (<code>/profile</code>, <code>PUT /profile</code>, semua role) — ubah nama/telepon/password sendiri tanpa buka <i>Master → Pengguna</i>.</li>
</ol>
</div></div>

<div class="card mb-3" id="baru-180-184"><div class="card-header"><h3 class="card-title">✨ Baru 180–184</h3></div><div class="card-body">
<ol class="mb-0">
<li><b>Matriks gate kas & bukti</b>: <code>POST /cash</code> keluar &gt;Rp10jt, <code>POST /returns</code> &gt;Rp2jt, <code>/consignments/{consignment}/settle</code>, <code>/debts/bulk-pay</code> + <code>/debts/{debt}</code>, <code>/purchases/{purchase}/pay</code>, <code>/technicians/{id}/pay</code> + <code>/technicians/pay-all</code> — keluar &gt;Rp10jt hanya owner/admin + wajib foto; retur ber-uang wajib TTD (+ foto bila &gt;Rp2jt).</li>
<li><b>Ulangi transaksi/pengeluaran</b>: tombol 🔁 di detail transaksi (<code>/sales/{sale}</code>, prefill POS) & riwayat pengeluaran (<code>/expenses</code>, prefill form + akun kas).</li>
<li><b>Pajak & kembalian di struk</b>: baris Pajak bila &gt;0 + baris KEMBALIAN bila bayar &gt; total — tampil di struk browser/WA/thermal & invoice (<code>/pos/print/{sale}</code>, <code>/services/{service}/print</code>, <code>/sales/{sale}/invoice</code>).</li>
<li><b>Keranjang → bundling 1-klik</b>: kartu <i>Sering Dibeli Bersamaan</i> di laba rugi (<code>/reports/profit</code>) + tombol buat promo bundling ter-prefill di daftar promo (<code>/promos</code>).</li>
<li><b>Sorot terlambat</b>: baris merah + badge <i>Terlambat N hari</i> di daftar tiket (<code>/services</code>) sesuai definisi SLA laporan.</li>
</ol>
</div></div>

<div class="card mb-3" id="baru-196-199"><div class="card-header"><h3 class="card-title">✨ Baru 196–199</h3></div><div class="card-body">
<ol class="mb-0">
<li><b>Shortcut <kbd>/</kbd></b>: tekan garis miring di mana saja untuk fokus ke pencarian navbar.</li>
<li><b>Versi aplikasi</b>: badge <code>v{APP_VERSION}</code> di footer + kartu Versi di halaman ini.</li>
<li><b>Import unit IMEI</b>: <code>/units/template</code> + <code>/units/import</code> — IMEI wajib unik, produk/supplier tak dikenal ditolak.</li>
<li><b>Import saldo awal</b>: <code>/debts/template</code> + <code>/debts/import</code> — hanya catat hutang/piutang, tanpa mutasi kas.</li>
<li><b>Import pengeluaran</b>: <code>/expenses/template</code> + <code>/expenses/import</code> — tiap baris = expense + kas keluar (gate &gt;Rp10jt).</li>
<li><b>Import pembelian</b>: <code>/purchases/template</code> + <code>/purchases/import</code> — kolom <code>items</code> = <code>SKU:qty:harga;…</code>, efek sama seperti form manual.</li>
</ol>
</div></div>

<div class="card mb-3" id="baru-200-204"><div class="card-header"><h3 class="card-title">✨ Baru 200–204</h3></div><div class="card-body">
<ol class="mb-0">
<li><b>Jadwal ulang booking massal</b>: centang booking → <i>Geser Jadwal</i> (<code>POST /bookings/reschedule-selected</code>, maks 50, hanya <i>menunggu/dikonfirmasi</i> tanpa tiket; tanggal baru ATAU +N hari 1–90).</li>
<li><b>Geser jatuh tempo massal</b>: centang di <i>Hutang & Piutang</i> (<code>POST /debts/bulk-due-date</code>, maks 50 belum lunas; tanggal baru menang atas +N hari 1–90, lunas dilewati).</li>
<li><b>Duplikat promo massal</b>: centang di <i>Promo</i> → <i>Duplikat Massal</i> (<code>POST /promos/bulk-duplicate</code>, kode <code>KODE-COPY</code> nonaktif) / <i>Duplikat ke Toko</i> (<code>POST /promos/bulk-duplicate-all</code>, maks 20, kode <code>KODE-KODETOKO</code>).</li>
<li><b>Geser estimasi service massal</b>: centang tiket aktif → <i>Geser Estimasi</i> (<code>POST /services/estimasi-selected</code>, maks 50; geser ±1–30 hari ATAU set tanggal, final dilewati).</li>
<li><b>Perpanjang garansi massal</b>: centang garansi aktif (<code>POST /warranties/extend-selected</code>, maks 50; +N hari 1–365 default 30 dari maks hari ini/akhir lama).</li>
</ol>
</div></div>

<div class="card mb-3" id="baru-206-209"><div class="card-header"><h3 class="card-title">✨ Baru 206–209</h3></div><div class="card-body">
<ol class="mb-0">
<li><b>Saldo berjalan kas</b>: di <i>Kas</i> (<code>/cash</code>, filter <code>?akun=</code> + kolom Saldo Akun berjalan, ikut <code>?export=csv</code>) — dihitung dari seluruh riwayat per akun.</li>
<li><b>Rincian per akun di arus kas</b>: di <i>Arus Kas</i> (<code>/cash/flow</code> bulan <code>?bulan=</code> & tahun <code>?tahun=</code>, ikut <code>?export=csv</code>) — tabel masuk/keluar/net + sorot ★ Terbesar.</li>
<li><b>Kelola akun kas</b>: di <i>Kas</i> (<code>POST /cash/accounts</code> tambah + saldo awal terjurnal <code>modal</code> + <code>PUT /cash/accounts/{account}</code> nama/default).</li>
</ol>
</div></div>

<div class="card mb-3" id="baru-190-194"><div class="card-header"><h3 class="card-title">✨ Baru 190–194</h3></div><div class="card-body">
<ol class="mb-0">
<li><b>Audit pencarian</b>: kolom <code>?q</code>/<code>?search</code> kini konsisten di semua daftar (normalisasi HP 08↔628 di tiket & pelanggan).</li>
<li><b>Preset tanggal</b>: tombol Hari ini / 7 Hari / Bulan ini mengisi filter otomatis di Kas, Riwayat Penjualan, Laporan Service, dan Laba Rugi.</li>
<li><b>Filter tanggal tiket</b>: <code>/services?from=&amp;to=</code> — ikut terbawa ke export CSV & tautan Laporan Periode.</li>
<li><b>Filter bulan booking</b>: <code>/bookings?bulan=YYYY-MM</code> — ikut export CSV.</li>
<li><b>Filter periode garansi</b>: <code>/warranties?from=&amp;to=</code> berdasarkan tanggal mulai — ikut export CSV.</li>
<li><b>Filter bulan pembelian</b>: <code>/purchases?bulan=YYYY-MM</code> — ikut kedua export CSV (ringkas & rincian).</li>
</ol>
</div></div>

<div class="card mb-3" id="baru-185-189"><div class="card-header"><h3 class="card-title">✨ Baru 185–189</h3></div><div class="card-body">
<ol class="mb-0">
<li><b>Pencarian global</b>: kotak search di navbar (<code>/search?q=</code>) — hasil dikelompokkan (penjualan by invoice, service by tiket/IMEI, pelanggan by nama/HP, produk by nama/SKU, unit by IMEI/serial; maks 5 per kelompok, scope toko aktif) + <i>Lihat Semua</i> ke halaman masing-masing.</li>
</ol>
</div></div>

<div class="card mb-3" id="baru-205-209"><div class="card-header"><h3 class="card-title">✨ Baru 205–209</h3></div><div class="card-body">
<ol class="mb-0">
<li><b>Lonceng notifikasi</b>: badge 🔔 di navbar polling tiap 60 detik (<code>GET /notifications/count</code>: siap diambil + overdue + booking menunggu, scope toko) — tanpa bunyi.</li>
<li><b>Ticker promo TV</b>: baris promo aktif berjalan di bawah <code>/display</code> (ikut filter toko).</li>
<li><b>Badge mendesak</b>: baris merah + badge ⚡ untuk tiket prioritas mendesak di daftar service.</li>
<li><b>Posisi antrean</b>: "N tiket di depan Anda" di <code>/track</code> (tiket aktif lebih tua, toko sama).</li>
<li><b>Ajakan servis berkala</b>: digest mingguan WA ke pelanggan dormant &gt;6 bulan (maks 50/toko, cooldown 90 hari).</li>
</ol>
</div></div>

<div class="card mb-3" id="baru-210-214"><div class="card-header"><h3 class="card-title">✨ Baru 210–214</h3></div><div class="card-body">
<ol class="mb-0">
<li><b>Laci kas BT/USB/LAN + auto</b>: tombol 🗄️ <i>Buka Laci (BT/USB)</i> di struk (<code>/pos/print/{sale}</code>) & nota service (<code>/services/{service}/print</code>) — pulsa ESC <code>p</code> 5 byte tanpa cetak (laci wajib ke port RJ11 printer); <i>Test Buka Laci</i> per printer LAN di <i>Printer & Cetak</i> (<code>POST /settings/printers/{id}/drawer</code>); centang <i>Buka laci otomatis setelah bayar tunai</i> di <i>Printer & Cetak → Branding</i> (drawer-kick via BT/USB terakhir dipakai, dilewati diam-diam bila belum connect).</li>
</ol>
</div></div>

<div class="card mb-3" id="baru-216-219"><div class="card-header"><h3 class="card-title">✨ Baru 216–219</h3></div><div class="card-body">
<ol class="mb-0">
<li><b>Perbaiki massal aman 1-klik</b>: tombol 🛠️ <i>Perbaiki Semua yang Aman</i> di <i>Kesehatan Data</i> (<code>POST /health/fix-all-safe</code>) — 4 aksi aman berurutan (unit terjual, unit yatim, komisi batal, garansi kedaluwarsa), gagal dilewati + dilaporkan, stok/sale/kas/hutang tetap manual.</li>
<li><b>Verifikasi backup + auto-verify</b>: tombol <i>Verifikasi</i> per baris di <i>Backup Data</i> (<code>GET /settings/backup/{file}/verify</code>, read-only: SQL = header + hitung tabel/INSERT, JSON = valid + hitung kunci) + otomatis tiap buat backup & <code>backup:weekly</code> (label SEHAT/RUSAK).</li>
<li><b>Demo ON/OFF 1-klik</b>: tombol 🧪 <i>Muat Data Demo</i> (<code>POST /settings/backup/demo-seed</code>, ditolak bila <code>DEMO-*</code> ada) + 🗑 <i>Bersihkan Demo</i> (<code>POST /settings/backup/demo-clean</code>, checkbox + ketik <code>HAPUS DEMO</code>) di <i>Backup Data</i> (<code>/settings/backup</code>).</li>
</ol>
</div></div>

<div class="card mb-3" id="baru-125-129"><div class="card-header"><h3 class="card-title">✨ Baru 125–129</h3></div><div class="card-body">
<ol class="mb-0">
<li><b>Perjanjian cicilan</b>: dari detail transaksi cicilan/DP → <i>Perjanjian</i> (<code>/sales/{sale}/agreement</code>); <b>massal</b> centang di <i>Riwayat Transaksi</i> → <i>Cetak Perjanjian</i> (≤20, <code>/sales/agreements/bulk</code>).</li>
<li><b>Surat jalan mutasi</b>: tiap transfer antar toko punya kode batch <code>TRM-…</code> → cetak pengantarnya di <i>Stok → Mutasi → Surat Jalan</i> (<code>/stocks/mutations/surat-jalan?batch=</code>, rincian SKU/qty + IMEI).</li>
<li><b>Surat hutang satuan</b>: tombol <i>Surat Tagihan</i> di tiap baris <i>Hutang & Piutang</i> (<code>/debts/{debt}/statement</code>); massal tetap via <i>Cetak Tagihan Massal</i>.</li>
<li><b>Log gagal login</b>: <i>Dashboard → Aktivitas → Arsip</i> (<code>/activity/log?date=</code>) — section bawah menampilkan 50 upaya login gagal terakhir (email disamarkan + IP).</li>
<li><b>Retensi log otomatis</b>: tiap backup mingguan (<code>backup:weekly</code> Senin 02:00) file log aktivitas berumur &gt;90 hari dihapus otomatis.</li>
</ol>
</div></div>

<div class="card mb-3" id="tv"><div class="card-header"><h3 class="card-title">📺 Layar TV Antrean</h3></div><div class="card-body">
<ol class="mb-0">
<li>Buka <code>/display</code> (tanpa login, cocok untuk TV toko; <code>?store=</code> untuk 1 toko) — hanya nomor tiket + status + estimasi, tanpa data pelanggan.</li>
<li>Auto-refresh 30 detik + <b>bunyi chime 3x + banner 🔔</b> saat ada HP yang baru siap diambil (klik <i>Tes Bunyi</i> untuk cek speaker TV).</li>
</ol>
</div></div>

<div class="card mb-3" id="tampilan"><div class="card-header"><h3 class="card-title">🌙 Tampilan, Suara & Offline</h3></div><div class="card-body">
<ol class="mb-0">
<li><b>Mode gelap</b>: tombol 🌙/☀️ di navbar (tersimpan otomatis, mengikuti HP saat pertama).</li>
<li><b>Mode kasir</b>: tombol di POS — tampilan ringkas layar sentuh (scan & bayar diperbesar).</li>
<li><b>Beep scan</b>: bunyi tiap item masuk + sukses/gagal bayar; bisu via tombol 🔊/🔇 (pilihan tersimpan).</li>
<li><b>Offline</b>: halaman utama, login, lacak & booking tetap terbuka tanpa internet; transaksi selalu butuh koneksi. Ada notif otomatis bila versi baru tersedia → klik <i>Update</i>.</li>
</ol>
</div></div>

<div class="card mb-3" id="cetak"><div class="card-header"><h3 class="card-title">🖨️ Cetak / Printer</h3></div><div class="card-body">
<ul class="mb-0">
<li><b>Browser (semua printer)</b>: dari struk/nota klik <i>Cetak</i> → pilih printer di dialog browser. Selalu tersedia, tanpa setting.</li>
<li><b>Bluetooth/USB thermal (58/80mm)</b>: di halaman <i>Printer & Cetak</i> pilih template 58/80mm → browser membuka tampilan struk kecil → <i>Share/Print</i> ke printer thermal yang sudah di-pairing.</li>
<li><b>LAN/WiFi</b>: daftarkan IP + port printer di <i>Printer & Cetak</i> → tombol <i>Test</i> → cetak nota langsung dari server.</li>
<li><b>Branding struk</b>: nama toko, alamat & footer nota diatur di <i>Printer & Cetak → Branding</i>.</li>
</ul>
</div></div>

<div class="card mb-3" id="wa"><div class="card-header"><h3 class="card-title">📲 WhatsApp</h3></div><div class="card-body">
<ol class="mb-0">
<li>Isi <b>Gateway URL + Token</b> di <i>Master → WhatsApp</i> (mendukung Fonnte/Wablas/gateway generik HTTP POST).</li>
<li>Klik <b>Simpan</b> lalu <b>Test kirim</b> ke nomor sendiri.</li>
<li>Tanpa gateway, pesan dicatat di log (mode <i>antri</i>) — tidak hilang, bisa dikirim ulang via tombol <i>Retry</i>.</li>
<li>Template notif (service, penjualan, booking, digest) bisa diubah — placeholder: <code>{nama} {tiket} {status} {total}</code> (+ <code>{sisa} {jadwal} {device} {track}</code>).</li>
<li>Jika log <i>gagal/antri</i> &gt;5, muncul di widget <i>Perlu Perhatian</i>.</li>
</ol>
</div></div>

<div class="card mb-3" id="tutup-kasir"><div class="card-header"><h3 class="card-title">💰 Tutup Kasir</h3></div><div class="card-body">
<ol class="mb-0">
<li>POS → <b>Tutup Kasir</b> (atau Keuangan → Kas → <i>Tutup Kasir/Handover</i>).</li>
<li>Hitung fisik tiap akun kas, masukkan ke kolom <i>fisik</i> → selisih tampil otomatis.</li>
<li>Cetak <b>berita acara</b> & tanda tangan serah terima shift.</li>
<li>Cek rekap harian di <i>Riwayat Transaksi → Tutup Kasir</i>. Dari sana bisa <b>kirim rekap via WA</b> dan <b>📊 Kirim Digest Sekarang</b>.</li>
</ol>
</div></div>

<div class="card mb-3" id="monitoring"><div class="card-header"><h3 class="card-title">🏢 Monitoring Pusat</h3></div><div class="card-body">
<ol class="mb-0">
<li>Khusus <b>owner/superadmin</b>: buka <i>Master → 🏢 Monitoring Pusat</i> (<code>/stores/monitor</code>).</li>
<li>Pantau omzet, transaksi, service aktif & stok kritis <b>semua toko</b> dalam satu layar.</li>
<li>Untuk cek satu toko, ganti <b>toko aktif</b> di kanan atas lalu buka Dashboard.</li>
</ol>
</div></div>

<div class="card mb-3" id="kesehatan"><div class="card-header"><h3 class="card-title">🩺 Kesehatan Data & Aktivitas</h3></div><div class="card-body">
<ol class="mb-0">
<li>Buka <i>Dashboard → <b>Kesehatan Data</b></i> (<code>/health</code>, khusus superadmin/owner) untuk temuan anomali — <b>8 cek</b>: unit terjual bermasalah, stok negatif, lompatan nomor invoice/kas/hutang, komisi yatim, unit service yatim, garansi kedaluwarsa.</li>
<li><b>4 aksi aman</b> tersedia langsung: tandai unit terjual/tersedia, hapus komisi yatim, tandai garansi kedaluwarsa.</li>
<li>Jejak perubahan pengguna tercatat di <i><b>Aktivitas</b></i> (<code>/activity</code>).</li>
</ol>
</div></div>

<div class="card mb-3" id="backup"><div class="card-header"><h3 class="card-title">💾 Backup Data</h3></div><div class="card-body">
<ol class="mb-0">
<li>Khusus superadmin/owner: <i>Master → <b>Backup Data</b></i> (<code>/settings/backup</code>).</li>
<li>Klik <b>Buat Backup Sekarang</b> — hasil <code>.sql</code> (via mysqldump) atau <code>.json</code> bila mysqldump tak tersedia. <b>Otomatis tiap Senin 02:00</b> (<code>backup:weekly</code>, file <code>auto-*</code>, 4 terakhir dipertahankan).</li>
<li><b>Unduh ZIP CSV</b>: arsip semua-toko (penjualan, service, produk, pelanggan, kas, hutang) untuk audit/Excel.</li>
<li>File tersimpan di <code>storage/app/backups</code> — bisa <b>diunduh / dihapus</b> dari halaman yang sama.</li>
<li><b>Restore .sql</b>: via CLI <code>mysql -u root -p nama_db &lt; backup-XXXX.sql</code> atau phpMyAdmin → Import (hentikan app & backup dulu). File <code>.json</code> hanya arsip baca — impor manual per tabel bila perlu.</li>
<li>Jadwalkan backup berkala + simpan salinan di luar server (flashdisk/Google Drive).</li>
<li><b>Checklist penyiapan</b>: buka <i>Dashboard → <b>Setup Checklist</b></i> (<code>/setup</code>) — pandu toko baru sampai 100%.</li>
</ol>
</div></div>

<div class="card mb-3" id="demo"><div class="card-header"><h3 class="card-title">🧪 Data Demo Showroom</h3></div><div class="card-body">
<ol class="mb-2">
<li><b>Isi paket demo</b>: 15 penjualan + 1 retur, 8 tiket service (semua status), 3 pembelian supplier, 2 booking, kas/expense/hutang-piutang & garansi demo untuk showroom laporan. Stok <b>tidak</b> diubah.</li>
<li><b>Syarat</b>: <code>DatabaseSeeder</code> sudah jalan (toko <code>PST</code>, user <code>kasir@servicehp.id</code>, produk SKU & promo <code>WELCOME10</code> tersedia). Seeder demo <b>NONAKTIF default</b>.</li>
<li><b>Muat demo</b> (idempoten — skip bila data <code>DEMO-*</code> sudah ada atau file <code>storage/app/demo_seeded</code> ada):
<pre class="bg-light p-2 small mb-2">php artisan db:seed --class=DemoTransactionSeeder</pre>
</li>
<li><b>Prefix DEMO-</b>: semua nomor demo diawali <code>DEMO-</code> agar mudah dikenali &amp; dibersihkan: <code>DEMO-JL-001…015</code> (penjualan), <code>DEMO-SVC-001…008</code> (service), <code>DEMO-PB-001…003</code> (pembelian), <code>DEMO-BK-001…002</code> (booking).</li>
<li><b>Bersihkan demo</b>: ⚠️ <b>backup dulu</b> via <i>Master → Backup Data</i> (<code>/settings/backup</code> → <i>Buat Backup Sekarang</i>). Lalu hapus via tinker + hapus flag:
<pre class="bg-light p-2 small mb-2">php artisan tinker --execute="App\Models\Sale::where('invoice_no','like','DEMO-%')->delete();"
php artisan tinker --execute="App\Models\Purchase::where('invoice_no','like','DEMO-%')->delete();"
php artisan tinker --execute="App\Models\ServiceTicket::where('ticket_no','like','DEMO-%')->delete();"
php artisan tinker --execute="App\Models\Booking::where('code','like','DEMO-%')->delete();"</pre>
<p class="text-secondary small mb-0">Setelah itu hapus flag <code>storage/app/demo_seeded</code> bila ingin memuat ulang, lalu cek <i>Riwayat Transaksi / Service / Pembelian</i> bahwa data <code>DEMO-</code> sudah hilang.</p>
</li>
</ol>
</div></div>

<div class="card mb-3" id="digest"><div class="card-header"><h3 class="card-title">📊 Digest Harian Otomatis</h3></div><div class="card-body">
<ol class="mb-2">
<li>Buka <b>Riwayat Transaksi → Tutup Kasir</b>, pilih tanggal → klik <b>📊 Kirim Digest Sekarang</b> untuk kirim manual (omzet + service) ke WA owner.</li>
<li>Otomatis tiap hari <b>21:00</b> via scheduler (<code>php artisan digest:daily</code>, rekap <i>kemarin</i>) dan tiap <b>Senin 07:00</b> rekap mingguan (<code>php artisan digest:weekly</code>, + ringkasan kesehatan data & booking 7 hari ke depan).</li>
<li>Isi pesan harian juga mencakup: <b>pengingat booking besok, hutang jatuh tempo ≤7 hari, promo aktif, tiket mendesak & pembatalan basi</b>.</li>
<li>Perintah manual: <code>php artisan digest:daily --date=2026-11-22 --store=1</code> (<code>--store</code> = id/kode toko, opsional).</li>
<li>Di Windows, buat <b>Task Scheduler → Create Basic Task → tiap 1 menit</b> dengan action <code>php D:\project laravel\servicehp\artisan schedule:run</code> agar jadwal 21:00 berjalan. Alternatif: jalankan manual tiap malam.</li>
</ol>
<p class="text-secondary small mb-0">Tanpa gateway, pesan berstatus <i>antri</i> di log WA (<i>Master → WhatsApp → Log</i>).</p>
</div></div>

<div class="card mb-3" id="shortcut"><div class="card-header"><h3 class="card-title">⌨️ Shortcut Keyboard</h3></div><div class="card-body">
<ul class="mb-0">
<li><kbd>F2</kbd> = fokus kolom scan POS.</li>
<li><kbd>Enter</kbd> di kolom scan POS = tambah item.</li>
<li><kbd>F4</kbd> = bayar tunai (isi nominal → Enter).</li>
<li><kbd>F9</kbd> = tahan struk (isi <i>Nama utk ditahan</i> dulu).</li>
<li><kbd>Esc</kbd> = reset form POS.</li>
<li><kbd>/</kbd> = fokus ke pencarian navbar.</li>
<li><kbd>Ctrl</kbd>+<kbd>P</kbd> = cetak halaman/struk aktif.</li>
<li><kbd>Ctrl</kbd>+<kbd>F</kbd> = fokus pencarian di tabel (browser).</li>
</ul>
</div></div>

<div class="card mb-3" id="csv"><div class="card-header"><h3 class="card-title">📥 Format CSV Import Produk</h3></div><div class="card-body">
<p class="text-secondary">Menu <i>Produk & HP → Import</i>. Header <b>wajib</b> baris pertama (huruf kecil, pemisah koma <code>,</code>):</p>
<pre class="bg-light p-2 small mb-2">sku,nama,type,brand,category,harga_beli,harga_jual,min_stock</pre>
<ul class="mb-0">
<li><code>nama</code> wajib; <code>type</code> salah satu: <code>hp_baru, hp_bekas, refurbished, aksesoris, sparepart, pulsa, paket_data, lain</code>.</li>
<li><code>brand</code>/<code>category</code> dibuat otomatis bila belum ada.</li>
<li><code>sku</code> unik — baris dengan SKU sama akan <b>update</b>, SKU kosong → dibuatkan otomatis.</li>
<li><code>harga_beli</code>/<code>harga_jual</code> angka (default 0), <code>min_stock</code> bilangan bulat (default 5).</li>
<li>Contoh: <code>HP001,Xiaomi Redmi 13,hp_baru,Xiaomi,HP,1500000,1750000,2</code></li>
<li>File dari tombol <b>Export</b> memakai <code>;</code> (Excel Indonesia) — ganti jadi <code>,</code> sebelum import.</li>
</ul>
</div></div>

<div class="card mb-3" id="keamanan"><div class="card-header"><h3 class="card-title">🔒 Keamanan Akun</h3></div><div class="card-body">
<ul class="mb-0">
<li>Login dibatasi <b>10x/menit per IP</b> (lebih dari itu ditolak sementara 429) — pesan gagal selalu generik agar tidak membocorkan email mana yang terdaftar.</li>
<li><b>Guard stok</b>: transaksi yang sudah punya retur <b>tidak bisa dihapus</b> (agar stok tidak ganda) — koreksi lewat menu Retur.</li>
<li>Selalu <b>logout</b> setelah shift; tidak ada registrasi mandiri — akun baru hanya dibuat owner/superadmin via <i>Master → Pengguna</i>.</li>
</ul>
</div></div>

<div class="card mb-3" id="troubleshoot"><div class="card-header"><h3 class="card-title">🛠️ Troubleshooting Printer</h3></div><div class="card-body">
<ul class="mb-0">
<li><b>Tidak keluar kertas (BT/USB)</b>: pastikan printer sudah pairing di HP/komputer, kertas thermal terpasang benar (sisi mengkilap menghadap head), ulangi Share → pilih printer yang sama.</li>
<li><b>Hasil cetak terpotong</b>: ganti template 58↔80mm sesuai kertas, matikan opsi <i>fit to page</i> di dialog cetak.</li>
<li><b>LAN: Test gagal</b>: cek IP/port printer, pastikan satu jaringan dengan server, matikan firewall port terkait, ping IP printer dulu.</li>
<li><b>Struk browser kosong</b>: nonaktifkan <i>ad-blocker</i> untuk domain ini, izinkan popup untuk tombol cetak struk.</li>
<li><b>Huruf/logo aneh</b>: gunakan font monospace bawaan template, hindari emoji di nama produk untuk struk thermal.</li>
</ul>
</div></div>

<div class="card mb-3" id="changelog">
  <div class="card-header">
    <h3 class="card-title">📝 Changelog</h3>
  </div>
  <div class="card-body">
    <ul class="mb-2">
      <li><b>v1.0 — Fondasi multi-toko</b>: master toko, pengguna & role, produk/brand/kategori, stok awal per toko.</li>
      <li><b>v2.0 — POS + service</b>: kasir (tunai/split/cicilan/DP, tahan struk, retur, tukar tambah), tiket service + QC + garansi, tracking & booking publik.</li>
      <li><b>v3.0 — Keuangan</b>: kas masuk/keluar & transfer, hutang-piutang + cicilan, laba rugi + neraca, budget, target omzet, tutup kasir & handover.</li>
      <li><b>v4.0 — WA + printer</b>: notifikasi WA (service/penjualan/booking/digest), cetak struk/nota/invoice/label/kartu massal, printer browser/BT/USB/LAN.</li>
      <li><b>v5.0 — PWA, TV & monitoring</b>: mode offline + update otomatis, layar TV antrean, digest harian/mingguan, monitoring pusat, kesehatan data & log aktivitas.</li>
      <li><b>v6.0 — Cetak universal</b>: invoice/PO/voucher/tiket/tagihan/kartu/QR/label massal (≤50 + page-break), surat jalan, perjanjian cicilan, raster thermal + self-test.</li>
      <li><b>v6.1 — Bantuan bisa cetak</b>: tombol 🖨️ Cetak di halaman ini (area konten saja, tanpa Daftar Isi) + section changelog ini.</li>
      <li><b>v6.2 — Dok 180–184</b>: matriks gate kas & bukti + ulangi transaksi, pajak & kembalian struk, bundling 1-klik, sorot terlambat.</li>
      <li><b>v6.3 — Baru 200–204</b>: jadwal ulang booking massal, geser jatuh tempo massal, duplikat promo massal (+ ke toko), geser estimasi service massal, perpanjang garansi massal.</li>
      <li><b>v6.4 — Baru 210–214</b>: laci kas BT/USB/LAN + buka otomatis setelah bayar tunai.</li>
      <li><b>v6.5 — Baru 216–219</b>: perbaiki massal aman 1-klik, verifikasi backup + auto-verify, demo ON/OFF 1-klik.</li>
    </ul>
    <p class="text-secondary small mb-0">
      Lihat README untuk detail.
    </p>
  </div>
</div>

</div>
<div class="col-lg-4">
<div class="card no-print"><div class="card-header"><h3 class="card-title">Daftar Isi</h3></div>
<div class="list-group list-group-flush">
<a class="list-group-item" href="#pos">🧾 POS / Kasir</a>
<a class="list-group-item" href="#service">🔧 Service</a>
<a class="list-group-item" href="#inventory">📦 Inventory & IMEI</a>
<a class="list-group-item" href="#belanja">🛒 Saran Belanja</a>
<a class="list-group-item" href="#harga-massal">💲 Harga Massal</a>
<a class="list-group-item" href="#keuangan">💼 Keuangan</a>
<a class="list-group-item" href="#cetak-massal">🖨️ Cetak Massal</a>
<a class="list-group-item" href="#crm">👥 CRM Lanjutan</a>
<a class="list-group-item" href="#promo">🏷️ Promo</a>
<a class="list-group-item" href="#baru-96-99">✨ Baru 96–99</a>
<a class="list-group-item" href="#baru-100-119">✨ Baru 100–119</a>
<a class="list-group-item" href="#baru-120-124">✨ Baru 120–124</a>
<a class="list-group-item" href="#baru-131-144">✨ Baru 131–144</a>
<a class="list-group-item" href="#baru-150-153">✨ Baru 150–153</a>
<a class="list-group-item" href="#baru-155-159">✨ Baru 155–159</a>
<a class="list-group-item" href="#baru-160-164">✨ Baru 160–164</a>
<a class="list-group-item" href="#baru-165-169">✨ Baru 165–169</a>
<a class="list-group-item" href="#baru-170-174">✨ Baru 170–174</a>
<a class="list-group-item" href="#baru-175-179">✨ Baru 175–179</a>
<a class="list-group-item" href="#baru-180-184">✨ Baru 180–184</a>
<a class="list-group-item" href="#baru-185-189">✨ Baru 185–189</a>
<a class="list-group-item" href="#baru-190-194">✨ Baru 190–194</a>
<a class="list-group-item" href="#baru-196-199">✨ Baru 196–199</a>
<a class="list-group-item" href="#baru-200-204">✨ Baru 200–204</a>
<a class="list-group-item" href="#baru-205-209">✨ Baru 205–209</a>
<a class="list-group-item" href="#baru-206-209">✨ Baru 206–209</a>
<a class="list-group-item" href="#baru-210-214">✨ Baru 210–214</a>
<a class="list-group-item" href="#baru-216-219">✨ Baru 216–219</a>
<a class="list-group-item" href="#baru-125-129">✨ Baru 125–129</a>
<a class="list-group-item" href="#tv">📺 Layar TV</a>
<a class="list-group-item" href="#tampilan">🌙 Tampilan & Offline</a>
<a class="list-group-item" href="#cetak">🖨️ Cetak</a>
<a class="list-group-item" href="#wa">📲 WhatsApp</a>
<a class="list-group-item" href="#tutup-kasir">💰 Tutup Kasir</a>
<a class="list-group-item" href="#monitoring">🏢 Monitoring</a>
<a class="list-group-item" href="#kesehatan">🩺 Kesehatan Data</a>
<a class="list-group-item" href="#backup">💾 Backup</a>
<a class="list-group-item" href="#demo">🧪 Data Demo</a>
<a class="list-group-item" href="#digest">📊 Digest Harian</a>
<a class="list-group-item" href="#shortcut">⌨️ Shortcut</a>
<a class="list-group-item" href="#csv">📥 CSV Import</a>
<a class="list-group-item" href="#keamanan">🔒 Keamanan</a>
<a class="list-group-item" href="#troubleshoot">🛠️ Troubleshooting Printer</a>
<a class="list-group-item" href="#changelog">📝 Changelog</a>
</div></div>
@php
  $appVersion = env('APP_VERSION', '2026.11');
@endphp
<div
  class="card mt-3 no-print"
>
  <div
    class="card-header"
  >
    <h3
      class="card-title"
    >
      ℹ️ Versi Aplikasi
    </h3>
  </div>
  <div
    class="card-body"
  >
    <div
      class="d-flex align-items-center gap-2"
    >
      <span
        class="badge bg-blue-lt"
      >
        v{{ $appVersion }}
      </span>
      <span
        class="text-secondary small"
      >
        ServiceHP — build {{ $appVersion }}
      </span>
    </div>
    <p
      class="text-secondary small mb-0 mt-2"
    >
      Diambil dari <code>APP_VERSION</code> di <code>.env</code> bila ada, bila tidak pakai tanggal build statis.
    </p>
  </div>
</div>
</div>
</div>
@endsection
