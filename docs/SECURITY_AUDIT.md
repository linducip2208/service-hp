# ServiceHP — Security Audit (Fase 1)

## Hasil uji penetrasi (regression permanen)

`tests/Feature/SecurityGatesTest.php` — 5 test, 34 asersi, hijau:
health/settings/stores/users/backup/activity-log/reminders/opname = 403 untuk kasir/teknisi;
teknisi dilarang bayar komisi sendiri; guest dialihkan ke login; throttle login ada.
`tests/Feature/CrudSmokeTest.php` — 45+ URL <500 sebagai owner. `WorkflowGatesTest.php` — cap
diskon 30%, bawah-modal, bukti >Rp1jt, role >Rp10jt, backdate vs kunci periode, gate retur >Rp2jt.

## Lubang yang SUDAH ditutup sesi ini

- `StoreController@index/show` tanpa guard (kasir bisa list toko) → guard owner.
- `pos/print` 500 (direktif `@elseTerima`, variabel tak dikirim, marker `[SIG:]` bocor ke struk).

## Lubang prioritas (dari matriks guard per-action)

**Kritis (baca lintas-toko):**
- `ReportController@profit` — `?store_id=` mengabaikan session; peran apa pun bisa baca P&L toko lain.
  → Perbaiki: hanya superadmin/owner boleh override store; lainnya paksa session.

**Tinggi (tulis tanpa guard):**
- `WarrantyController@claim/toTicket/card` — tanpa abort store; klaim garansi toko lain via ID.
- `PromoController@create/edit` — form tulis terbuka untuk semua peran (store/update/destroy sudah dijaga).
- `Customer/Supplier/Brand/Category` — CRUD+merge+import tanpa gate peran; customer tanpa abort store
  (merge/move lintas toko oleh peran apa pun).
- `UnitController` — list/create/import/timeline tanpa gate peran.
- `SaleController` — list/view/invoice/pay/destroy tanpa gate peran (teknisi/gudang bisa hapus penjualan).
- `Service/BookingController` — tiket/booking bisa dibuat-diubah-dihapus-transfer peran apa pun.

**Sedang (konsistensi):**
- PeriodLock absen di: Return, TradeIn, Secondhand, Consignment, Stock mutate/opname, Service recordCash.
- Gate nominal hanya di controller (tanpa invariant DB); kata-kata/kode HTTP tidak seragam (403 vs 422);
  bukti wajib inkonsisten antar modul.
- `PrinterController` aksi cetak/WA terbuka semua peran dalam toko (terima jika disengaja).

**Paparan publik:**
- `POST /track` mengembalikan baris tiket penuh + log; pencocokan digit HP = oracle enumerasi.
  Mitigasi: batasi field, captcha/rate per-tiket.
- `POST /booking` tanpa `exists:stores` (orphan bila store_id ngawur); `queue/take` counter global
  (tabrakan antar-toko).

**Sesi & log:**
- `SetActiveStore` tak revalidasi session store vs penugasan user; `switchStore` fallback
  `stores()->count()===0` melebarkan hak saat user tanpa penugasan.
- `LogActivity` tak mencatat GET (ekspor CSV massal buta audit); tanpa request-id/status.

## Aturan main

Daftar peran kanonis (pusatkan, jangan string literal): POS `superadmin,owner,admin,kasir`;
tulis inventori +uang `superadmin,owner,admin,gudang`; kunci/health/backup `superadmin,owner`.
Setiap jalur tulis baru (import/API/artisan) wajib lewat gate yang sama dengan controller.
