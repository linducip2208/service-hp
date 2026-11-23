<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Ringkasan pagi operasional (read-only, khusus hari ini) → WA owner tiap toko.
Schedule::command('digest:morning')->dailyAt('07:00');

// Ringkasan harian otomatis → WA owner tiap toko (rekap kemarin).
Schedule::command('digest:daily')->dailyAt('21:00');

// Rekap mingguan otomatis → WA owner tiap toko (7 hari terakhir).
Schedule::command('digest:weekly')->weeklyOn(1, '07:00');

// Backup DB otomatis mingguan → storage/app/backups/auto-* (retensi 4 file).
Schedule::command('backup:weekly')->weeklyOn(1, '02:00');
