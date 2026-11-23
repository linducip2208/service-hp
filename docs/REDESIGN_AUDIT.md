# ServiceHP — Redesign Audit: Dashboard (Fase 1)

Temuan dari brief §5 — status verifikasi terhadap kode saat ini:

1. **DashboardController besar** — TERKONFIRMASI (1000+ baris: statistik, alert, jadwal, target,
   chart, monitor). Rencana Fase 2: pecah ke query object/service
   (`App\Services\Dashboard\...`: StatsService, AlertService, ScheduleService, TargetService).
2. **`$storeId` ditulis dua kali** — PERLU CEK ulang saat refaktor (cari assignment ganda).
3. **`try/catch` menelan exception** — TERKONFIRMASI (`DailyDigestService` + controller: `catch → set kosong`).
   Dashboard terlihat normal saat data gagal dimuat. Rencana: log warning + badge "data sebagian".
4. **Definisi omzet vs kas diterima** — TERKONFIRMASI parsial: `sales.total` (omzet) vs `paid_amount`/kas.
   Dashboard campur keduanya tanpa label. Rencana: label eksplisit "Omzet (total invoice)" vs "Kas masuk".
5. **Filter cabang inkonsisten** — TERKONFIRMASI (`ReportController?store_id=` override bebas;
   beberapa widget paksa session). Rencana: satu resolver `resolveStore(request, user)`.
6. **Relasi pelanggan/service/penjualan/stok** — lihat DATABASE_RELATIONSHIP_AUDIT (link booking↔tiket
   tak ber-FK; snapshot denormalisasi; ref polimorfik stringly).
7. **Dashboard per peran** — BELUM ADA. Rencana Fase 2: kasir (jual + target harian + jadwal),
   teknisi (tiket saya + SLA + komisi), gudang (stok + opname + mutasi), owner (semua + antar-cabang).

Pendekatan (sesuai brief): **sempurnakan & susun ulang, bukan buang fitur**.
Struktur Fase 2: Header (toko+periode+search+notif+quick action) → KPI (8 kartu) → Analitik
(tren omzet/profit, payment mix, kategori, top produk, performa service/teknisi, perbandingan cabang) →
Operasional (antrean service, booking, pickup, beban teknisi, stok kritis, saran belanja, kas, piutang) →
Insight (target harian/bulanan/tahunan, retensi, repeat, deadstock). Semua widget dari DB aktual,
visibility per peran.
