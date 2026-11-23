<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Log Aktivitas (file-based, tanpa tabel/migration baru).
 *
 * Mencatat setiap request terautentikasi bermetode POST/PUT/PATCH/DELETE ke
 * storage/logs/activity-Y-m-d.log dengan format:
 *   [Y-m-d H:i:s] user:{nama}#{id} | role:{role} | toko:{id_toko} | {METHOD} {path} | ip:{ip}
 *
 * Dilewati: GET/HEAD/OPTIONS, file asset/statis, dan route login/logout.
 * Didaftarkan di grup `web` SETELAH SetActiveStore agar user + toko aktif
 * sudah tersedia; pencatatan dilakukan SETELAH request ditangani agar
 * status autentikasi sudah final.
 */
class LogActivity
{
    /** Prefix path yang dianggap asset/statis dan tidak perlu dicatat. */
    private const ASSET_PREFIXES = [
        'build/', 'assets/', 'storage/', 'css/', 'js/',
        'images/', 'img/', 'fonts/', 'vendor/',
    ];

    private const ASSET_FILES = [
        'favicon.ico', 'robots.txt',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $shouldLog = $this->shouldLog($request);

        /** @var Response $response */
        $response = $next($request);

        if ($shouldLog && auth()->check()) {
            $this->write($request);
        }

        return $response;
    }

    private function shouldLog(Request $request): bool
    {
        // Hanya aksi tulis.
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }

        $path = ltrim($request->path(), '/');

        // Lewati login/logout (autentikasi maupun sampah brute-force tidak dicatat di sini).
        if ($path === 'login' || str_starts_with($path, 'login/') || $path === 'logout' || str_starts_with($path, 'logout/')) {
            return false;
        }

        // Lewati health-check bawaan Laravel.
        if ($path === 'up') {
            return false;
        }

        // Lewati asset/statis.
        foreach (self::ASSET_FILES as $file) {
            if ($path === $file) {
                return false;
            }
        }
        foreach (self::ASSET_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        return true;
    }

    private function write(Request $request): void
    {
        try {
            $user = auth()->user();
            $storeId = session('active_store_id') ?? $user->active_store_id ?? '-';

            $line = sprintf(
                '[%s] user:%s#%s | role:%s | toko:%s | %s %s | ip:%s',
                now()->format('Y-m-d H:i:s'),
                $this->clean((string) ($user->name ?? '-')),
                $user->id ?? '-',
                $this->clean((string) ($user->role ?? '-')),
                $this->clean((string) $storeId),
                $request->method(),
                $this->clean('/' . ltrim($request->path(), '/')),
                $this->clean((string) $request->ip())
            );

            $file = storage_path('logs' . DIRECTORY_SEPARATOR . 'activity-' . now()->format('Y-m-d') . '.log');
            @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Logging tidak boleh menggagalkan request utama.
        }
    }

    /** Buang karakter newline agar format satu-baris-per-aksi tetap terjaga. */
    private function clean(string $value): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $value));
    }
}
