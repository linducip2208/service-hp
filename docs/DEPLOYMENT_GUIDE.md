# ServiceHP — Deployment Guide (Fase 14)

## Prasyarat

PHP ^8.2 + ext-gd, Composer, Node 18+, MySQL 8, `mysqldump` di PATH (untuk backup SQL).

## Deploy produksi (ringkas)

```bash
git clone https://github.com/linducip2208/service-hp.git
cd service-hp
composer install --no-dev -o
cp .env.production.example .env   # JANGAN salin .env lokal (isinya kredensial dev)
php artisan key:generate
# edit .env: APP_DEBUG=false, APP_URL, DB_*, MAIL_*
php artisan migrate --force
php artisan db:seed --force       # buat owner/superadmin awal
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
# ganti password akun seed SEGERA via menu Pengguna
```

## Scheduler (wajib untuk digest 07:00/21:00 + backup Senin 02:00)

Windows Task Scheduler → trigger tiap 1 menit:

```
php D:\path\artisan schedule:run   (Start in: D:\path)
```

Linux cron: `* * * * * php /path/artisan schedule:run >> /dev/null 2>&1`

## Operasional

- `php artisan backup:weekly` — jalan otomatis Senin 02:00 (retensi 4 file).
- Verifikasi backup: halaman Backup → Verifikasi (SEHAT/RUSAK).
- Restore SQL: `mysql -u user -p db < storage/app/backups/backup-XXXX.sql` (hentikan app dulu).
- Setelah `composer install` baru di server: ulangi `config/route/view:cache`.
- Jangan jalankan `php artisan config:cache` di mesin dev aktif tanpa `config:clear` setelahnya.

## Rollback cepat

Tiap fase di-commit terpisah di `main` — rollback = `git revert <hash>` + redeploy.
Tag rilis disarankan: `git tag v2026.11-fase14`.
