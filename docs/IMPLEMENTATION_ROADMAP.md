# ServiceHP — Implementation Roadmap (Enterprise 100/100)

> Dokumen hidup. Diperbarui tiap fase selesai: review → test → fix → regression → docs → fase berikut.

## Token desain (wajib dipakai semua halaman)

| Token | Nilai |
|---|---|
| Primary Blue | `#1769F5` |
| Navy | `#14243B` |
| Success | `#16A36A` |
| Warning | `#F59E0B` |
| Danger | Tabler default merah |
| Fondasi UI | Tabler 100%, Blade, tanpa framework baru |
| Aset | Lokal dulu (npm/vite); CDN hanya fallback terdaftar di SW |

Status migrasi token: `public/css/corporate.css` masih memakai biru lama (`#0f5fd0`/`#0f2440`) —
selaraskan ke token di atas pada Fase 2.

## Fase & status

| Fase | Nama | Status | Bukti |
|---|---|---|---|
| 1 | Repository discovery (6 dokumen audit) | SELESAI | `docs/` |
| 2 | Design system + dashboard | SELESAI kode (verifikasi visual browser pending) | sidebar, DashboardService, 8 KPI |
| 3 | POS & sales | SELESAI inti (E2E hijau; tanpa multi-cart/offline-queue) | `tests/Feature/PosE2ETest.php` |
| 4 | Service center + workflow engine | SELESAI inti (nextMap + kanban) | `KanbanDevicesTest` |
| 5 | Inventory & IMEI | SELESAI inti (tanpa multi-gudang formal/reservasi) | `InterconnectTest` 119 rute |
| 6 | Purchasing, HP bekas, tukar tambah | SELESAI inti (tanpa PO bertingkat/scoring) | gate >Rp10jt teruji |
| 7 | CRM | SELESAI inti (+perangkat 360; tanpa loyalty/referral) | `KanbanDevicesTest` |
| 8 | Teknisi & SDM | SELESAI inti (tanpa kanban beban/leaderboard) | report+komisi teruji |
| 9 | Finance & accounting | SENGAJA single-entry (lihat FINAL_AUDIT) | gates + PeriodLock teruji |
| 10 | Reporting & BI | SELESAI inti (CSV+filter+drill dasar) | `CrudSmokeTest` |
| 11 | UI/UX audit semua halaman | BERJALAN (crawler 119 + form 220 + tombol 849) | `InterconnectTest` |
| 12 | Database & security | SELESAI inti (gates + regression; tanpa 2FA/beban) | `SecurityGatesTest`, `WorkflowGatesTest` |
| 13 | Testing | SELESAI (31 passed / 160 asersi) | `php artisan test` |
| 14 | Quality gate + 5 dokumen final | SELESAI | `docs/FINAL_AUDIT.md` dkk |

## Aturan eksekusi (dari brief, mengikat)

- Pertahankan semua fitur berjalan; dilarang hapus fitur demi simplifikasi.
- Dilarang placeholder/fitur palsu/tombol mati/dropdown rusak/form inert.
- Tiap fase: implementasi → test → fix → regression → docs → fase berikut.
- Agen paralel hanya untuk area independen; batas kepemilikan file jelas.
- Migrasi destruktif dilarang menyentuh database produksi.
- Klaim akuntansi/pajak: audit aturan Indonesia dulu, tanpa klaim kepatuhan palsu.
