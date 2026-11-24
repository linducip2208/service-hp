# ServiceHP — Test Results (Fase 13/14)

Perintah wajib fase 13 (semua dijalankan, semua hijau):

| Perintah | Hasil |
|---|---|
| `php artisan test` | **31 passed / 160 assertions** |
| `php artisan route:list` | 285 baris, tidak ada duplikat nama |
| `php artisan migrate:status` | 0 pending (40 tabel `servicehp` valid) |
| `php artisan view:cache` | sukses (Blade terkompilasi bersih) |
| `php artisan config:cache` → `config:clear` | OK, dikembalikan NOT CACHED (aman untuk dev) |
| `npm run build` | sukses (bundle tailwind stub; bundle utama = `public/vendor` + `corporate.css`) |

## Cakupan (file → apa yang dibuktikan)

| File | Bukti |
|---|---|
| `ExampleTest` (2) | `/` redirect login; unit placeholder |
| `SecurityGatesTest` (5) | 403 kasir/teknisi di health/settings/stores/users/backup/log/reminders/opname; teknisi dilarang bayar komisi; guest redirect; throttle login |
| `CrudSmokeTest` (6) | 47 URL <500; CRUD brand/kategori/supplier/produk + hapus bersih |
| `PosE2ETest` | Jual tunai → struk+hadiah+invoice → stok 10→8 → hapus sale → stok 10 |
| `GiftNoteTest` | `[UCAPAN]/[SIG:]` tampil benar, tidak bocor ke struk |
| `WorkflowGatesTest` (5) | Cap diskon 30%, bawah-modal role-gated, bukti >Rp1jt, role >Rp10jt, backdate vs kunci, gate retur >Rp2jt, recalc kering |
| `InterconnectTest` | **119 route GET dibuka satu-satu, 0 error 500** (+ fixture + cleanup) |
| `InterconnectWriteTest` (4) | Siklus service + tolak lompatan, booking→tiket+pelanggan, transfer pulang-pergi balance, WA-log render |
| `KanbanDevicesTest` (2) | Kanban render+pindah; perangkat pelanggan 360 |
| `ProductsUiTest` (3) | Marker redesign produk; janitor residu uji |
| `BackupFileTest` | Verify SEHAT + download 200 |
| `DashboardRolesTest` | Dashboard 200 untuk superadmin/owner/kasir/teknisi(/gudang) |

## Celah cakupan yang diketahui

- Concurency (dua kasir jual unit terakhir bersamaan) belum diuji beban.
- Import CSV hanya happy-path template (belum fuzz baris rusak).
- Digest/WhatsApp sisi kirim nyata belum diuji (gateway log; dry-run teruji).
- Render visual (screenshot) tidak diuji — verifikasi manual di browser.
