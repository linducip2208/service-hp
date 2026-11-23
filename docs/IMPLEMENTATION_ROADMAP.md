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
| 1 | Repository discovery (6 dokumen audit) | berjalan (4 agen paralel) | `docs/` |
| 2 | Design system + dashboard | antre | — |
| 3 | POS & sales | antre | `tests/Feature/PosE2ETest.php` sudah hijau |
| 4 | Service center + workflow engine | antre | — |
| 5 | Inventory & IMEI | antre | — |
| 6 | Purchasing, HP bekas, tukar tambah | antre | — |
| 7 | CRM | antre | — |
| 8 | Teknisi & SDM | antre | — |
| 9 | Finance & accounting | antre (audit dulu, klaim hati-hati) | — |
| 10 | Reporting & BI | antre | — |
| 11 | UI/UX audit semua halaman | antre | — |
| 12 | Database & security | sebagian (gates + regression ada) | `tests/Feature/SecurityGatesTest.php` |
| 13 | Testing | berjalan (14 passed / 71 assertions) | `php artisan test` |
| 14 | Quality gate + 5 dokumen final | antre | — |

## Aturan eksekusi (dari brief, mengikat)

- Pertahankan semua fitur berjalan; dilarang hapus fitur demi simplifikasi.
- Dilarang placeholder/fitur palsu/tombol mati/dropdown rusak/form inert.
- Tiap fase: implementasi → test → fix → regression → docs → fase berikut.
- Agen paralel hanya untuk area independen; batas kepemilikan file jelas.
- Migrasi destruktif dilarang menyentuh database produksi.
- Klaim akuntansi/pajak: audit aturan Indonesia dulu, tanpa klaim kepatuhan palsu.
