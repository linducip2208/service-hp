# ServiceHP — Known Limitations (Fase 14)

1. **Akuntansi single-entry.** Tidak ada jurnal berpasangan/CoA/neraca. Jangan pakai
   untuk pelaporan pajak formal tanpa review akuntan.
2. **Hapus data permanen.** Tanpa soft-delete; hapus Store memusnahkan riwayat
   (CASCADE). Hapus produk/riwayat diblokir bila dipakai transaksi (benar),
   tetapi tidak ada arsip/restore.
3. **Polimorfisme stringly-typed.** `ref_type/ref_id` tanpa FK; rename class =
   riwayat yatim. Mitigasi: jangan rename model tanpa migrasi data.
4. **Kunci periode file-based** (`storage/app/period_lock.json`): tidak sinkron
   antar-server bila multi-server; backup file ini bersama database.
5. **WA gateway opsional.** Tanpa gateway, semua notifikasi berstatus `antri`
   (tercatat, tidak terkirim). Digest tetap jalan (dry-run tersedia).
6. **Foto TTD/bukti sebagai file publik** (`storage/app/public/*`): siapa pun yang
   tahu URL bisa mengakses; jangan simpan data KTP/kartu identitas di sini.
7. **Rate limit** hanya di login/booking/track/antrean; operasi uang mengandalkan
   gate peran, bukan throttle.
8. **Konkurensi stok** mengandalkan lock baris transaksi; belum ada uji beban
   dua-kasir-jual-unit-terakhir.
9. **Restore JSON** hanya arsip baca; restore penuh hanya dari `.sql`.
10. **QR garansi** memakai api.qrserver.com (satu-satunya dependensi runtime luar
    selain koneksi WA/LAN printer); cetak tetap jalan offline tanpa QR.
