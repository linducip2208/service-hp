# ServiceHP — Final Audit (Fase 14)

Tanggal: 2026-11-24. Cabang: `main`. Verifikasi: 31 test / 160 asersi hijau,
crawler 119 route GET + write-flows + regression gates.

## Keputusan arsitektur (final, mengikat)

- Fondasi tetap: Laravel 12 + Blade + **Tabler 100%** (tanpa framework baru).
- Aset pihak ketiga di-vendor lokal (`public/vendor/`) — tidak ada ketergantungan
  runtime ke CDN kecuali Google Fonts (fallback font sistem bila offline).
- Satu sumber kebenaran workflow: `ServiceController::updateStatus` (nextMap + gate
  QC/foto/TTD). Kanban dan tombol lain hanya memanggil endpoint yang sama.
- Uang & stok: semua tulis multi-tabel dalam `DB::transaction` di controller;
  gate nominal + bukti + kunci periode di level controller (belum ada invariant DB).
- Riwayat yang dikoreksi tidak dihapus diam-diam: hapus sale/service diblokir bila
  sudah ada retur/pelunasan lama; koreksi via Retur di periode berjalan.

## Yang TIDAK diklaim

- **Bukan double-entry accounting.** Laba rugi = agregat sederhana (omzet, HPP via
  profit item, beban). Tidak ada Chart of Accounts, jurnal berpasangan, atau neraca.
  Klaim kepatuhan akuntansi/pajak Indonesia **dilarang** sampai ada review aturan.
- **Bukan garansi keamanan absolut.** Matriks guard + regression ada, tetapi: uji
  penetrasi terbatas pada role×endpoint, tanpa uji beban/konkurensi, tanpa audit
  kriptografi. Lihat KNOWN_LIMITATIONS.md.
- **Skor 100/100 tidak diberikan** ke modul mana pun (lihat FEATURE_COMPLETION_MATRIX).
