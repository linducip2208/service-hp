# ServiceHP — Feature Matrix (Fase 1)

Sumber: audit read-only 4 agen paralel (routes/controllers/auth, database, views/aset/PWA, fitur/service/test).
Legenda: **full** = route + handler + persistensi ada; **partial** = jalan tapi ada celah; **stub** = tidak ditemukan (tidak ada stub di repo ini).

| # | Modul | Status | Bukti |
|---|---|---|---|
| 1 | POS | full | `POST /pos` → `PosController@store` (PeriodLock, cap diskon 30%, blokir bawah-modal, split tolerance Rp1, hold cart, cicilan+TTD, struk+gift) |
| 2 | Penjualan/closing/cicilan | full | `/sales`, `/{sale}`, `/invoice`, `/pay`, `/closing`+`wa`+`digest`, bulk invoice/agreement, kwitansi `KWT-*` |
| 3 | Retur/void | full | void 1-klik (hari ini+milik sendiri+full), gate >Rp2jt + bukti wajib + TTD |
| 4 | Tukar tambah | full | CRUD + link POS; bukti cetak via print-area |
| 5 | HP bekas | full | grade/IMEI/foto/TTD + gate >Rp10jt |
| 6 | Konsinyasi | full | titip + settle (gate >Rp10jt + bukti) |
| 7 | Produk | full | resource + bulk/bulk-assign/reorder/export-import/merge/duplicate/min-stock |
| 8 | Unit/IMEI | full | list/store/timeline + template/import (IMEI unik) |
| 9 | Stok | full | opname+import, mutasi+transfer+surat jalan, valuasi, deadstock |
| 10 | Service | full | resource + status/priority/transfer/sparepart + bulk ops + print + report; state map + gate QC/handover |
| 11 | Garansi | full | claim/ticket/card/cards + bulk remind/claim/extend |
| 12 | Booking | full | calendar + 5 bulk ops + remind; publik `/booking`, `/track` |
| 13 | Teknisi/komisi | full | target, pay/pay-all (owner-only), slip+bulk, vcard, report |
| 14 | Kas | full | masuk/keluar, transfer, akun, closing+lock, flow, voucher |
| 15 | Pengeluaran | full | + budget, bulk-category, import, tahunan; gate bukti >Rp1jt, role >Rp10jt |
| 16 | Hutang/piutang | full | + statements, bulk-pay/remind/due-date, import; gate >Rp10jt + bukti |
| 17 | Pembelian | full | + pay/received/WA/bulk-print/import; nota wajib >Rp5jt, role >Rp10jt |
| 18 | Promo | full | + qr-sheet/import/bulk on-off/extend/duplicate(-all); cap 100% |
| 19 | CRM | full | RFM/repeat/dup-warning, merge/move-store, member-card, broadcast, statement-WA, vcard |
| 20 | Supplier/brand/kategori | full | CRUD + template/import/merge/vcard |
| 21 | Toko | full | CRUD + monitor + target + copy-next-year; guard owner |
| 22 | Pengguna | full | CRUD + bulk role/status; proteksi superadmin terakhir + akun sendiri |
| 23 | Settings WA/printer | full | gateway+template+retry, printer+branding/test/drawer, kirim LAN/WA/struk |
| 24 | Backup/storage | full | mysqldump→fallback JSON, verify, demo seed/clean, CSV ZIP, audit file yatim |
| 25 | Health | full | 8 cek + 4 aksi aman + fix-all-safe (read-only + aman) |
| 26 | Aktivitas | full | timeline + file log + seksi auth-fail |
| 27 | Pengingat | full | 17 seksi agregat, ignore lokal, cetak |
| 28 | Antrean/display | full | nomor harian, TV 30 dtk + chime + promo ticker + banner mendesak |
| 29 | Search | full | global 5/kelompok + normalisasi HP + shortcut `/` |
| 30 | Digest/backup scheduler | full | morning 07:00, daily 21:00, weekly Senin 07:00, backup Senin 02:00; dry-run didukung |
| 31 | `profit:recalc` | partial | ada + dry-run, hanya isi profit kosong, **tidak terjadwal** (tool manual) |
| 32 | Laporan laba rugi | full | Top-10, kasir, kategori, MoM/YoY, ROI, PPh 0,5% info, narasi, CSV |

**Tidak ada stub.** Klaim README terverifikasi memetakan ke route+handler (mismatch hanya dokumentasi kecil:
`digest:morning` kurang disebut di seksi digest; `profit:recalc` tak terdokumentasi).

## Kesenjangan uji (prioritas)

1. Aturan workflow: cap diskon, bawah-modal, void, QC/handover, gate nominal, period-lock → **ditutup** oleh `tests/Feature/WorkflowGatesTest.php` (5 test).
2. Siklus service, sparepart, transfer, reminder — belum ada test.
3. Uang: split mismatch, cicilan/pay, handover closing, transfer kas, bulk-pay, settle — belum ada test.
4. Inventori: transfer+surat jalan, opname, valuasi/deadstock/reorder math, merge, import — belum ada test.
5. Otomatisasi: 5 console command (dry-run vs real, idempotensi, WA-once) — belum ada test.
6. Komms/print: gateway-missing vs terkirim, raster fallback, LAN gagal — belum ada test.
7. Multi-tenant & peran di luar matriks 403 — belum ada test.
8. Pelaporan: CSV/shape, arus kas tahunan, retention, search grouping — belum ada test.
