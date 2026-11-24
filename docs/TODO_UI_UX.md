# TODO — Perapihan UI/UX (dari screenshot Produk & audit)

Legenda: `[ ]` belum, `[x]` selesai. Target: tiap halaman index ikut pola KORPORAT yang sama.

## P0 — Halaman Produk (difoto, paling parah)
- [x] Header tombol bertumpuk (9 tombol) → grup: primer (+ Produk) + sekunder (dropdown "Alat & Laporan").
- [x] Banner info bertele-tele → ringkas 1 baris + dismissible.
- [x] Kartu import selalu terbuka → jadikan collapsible `<details>`.
- [x] Filter + tombol Filter penuh → baris kompak + tombol sejajar kanan.
- [x] Aksi per-baris (Detail/Edit/Duplikat × N) → dropdown "Aksi" + tombol utama.
- [x] Kolom foto kosong melulu ("Tanpa foto 19") → thumbnail 40px + placeholder ikon.
- [x] Pagination ganda (Previous/Next + angka) → satu pagination bawaan.
- [x] Badge `nonaktif` merah mencolok → netralkan (abu-abu), sorot hanya stok kritis.
- [x] Bersihkan data uji (`Gate Uji%`, `Kabel Uji%`) dari DB test.
- [ ] Thumbnail foto produk tampil di tabel (butuh kolom `photo` — sudah ada).

## P1 — Pola index korporat (terapkan ke semua index)
- [ ] Header standar: pretitle + judul + subdeskripsi 1 baris; primer kanan + "Alat" dropdown.
- [ ] Maks 3 tombol primer-inline; sisanya masuk dropdown.
- [ ] Alert ringkas + dismissible; tanpa paragraf instruksi panjang.
- [ ] Import selalu collapsible; template link di dalam.
- [ ] Filter: 1 baris (search + 2-3 select + tombol); pills untuk shortcut filter.
- [ ] Tabel: sticky header, thumbnail bila ada foto, badge status konsisten, kolom angka tabular kanan.
- [ ] Aksi baris: dropdown; pagination tunggal.
- [ ] Empty state: ikon + kalimat aksi + tombol.

## P2 — Sidebar/topbar baru (Fase 2, selesai di kode)
- [x] Grup aktivitas bisnis + role.
- [x] Badge tertunda (booking, siap diambil, stok kritis, hutang ≤7h).
- [x] Cari menu, favorit, terakhir dibuka, ingat grup, mini-mode, Ctrl+K.
- [x] Toast auto-dismiss gantikan flash statis.
- [ ] Uji mobile drawer + dark mode sidebar (butuh device asli).

## P3 — Dashboard (Fase 2, selesai di kode)
- [x] Service data terpusat (`DashboardService`) + log warning (tidak ditelan).
- [x] 8 KPI informatif + tren omzet vs profit terpisah + panel status operasional.
- [x] Peringatan berlapis urgensi + quick action per peran + seksi teknisi + ringkasan cabang.
- [x] Definisi bisnis eksplisit (omzet invoice vs kas diterima vs profit produk).
