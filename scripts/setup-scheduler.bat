@echo off
REM Scheduler ServiceHP - jalankan tiap 1 menit via Task Scheduler
REM Cara pakai: jalankan file ini sekali sebagai Administrator untuk daftarkan task,
REM atau buat manual sesuai README-ServiceHP.md
schtasks /create /tn "ServiceHP-schedule-run" /tr "php D:\project laravel\servicehp\artisan schedule:run" /sc minute /mo 1 /st 00:00 /f
echo Task terdaftar. Cek dengan: schtasks /query /tn "ServiceHP-schedule-run"
pause
