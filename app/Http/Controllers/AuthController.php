<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function loginForm()
    {
        if (Auth::check()) return redirect()->route('dashboard');
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            $this->logFail((string) $request->email, (string) $request->ip(), 'kredensial');
            return back()->withErrors(['email' => 'Email atau password salah.'])->withInput();
        }

        if (isset($user->is_active) && !$user->is_active) {
            $this->logFail((string) $request->email, (string) $request->ip(), 'nonaktif');
            return back()->withErrors(['email' => 'Akun nonaktif. Hubungi owner.'])->withInput();
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        // set active store
        $storeId = $user->active_store_id ?? $user->stores()->first()?->id ?? Store::where('is_active', true)->first()?->id;
        if ($storeId) session(['active_store_id' => $storeId]);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }

    /**
     * Catat percobaan login gagal ke storage/logs/auth-fail-Y-m-d.log (file-based, tanpa migration).
     * Privasi: TIDAK pernah menulis password; email disamarkan parsial (2 huruf awal
     * local-part + *** + @domain), mis. ad***@example.com. Throttle tetap milik
     * route (throttle:10,1) sehingga file log hanya efek samping aman.
     * Format: [Y-m-d H:i:s] login-gagal | email:{samaran} | sebab:{kredensial|nonaktif} | ip:{ip}
     */
    private function logFail(string $email, string $ip, string $reason): void
    {
        try {
            $masked = $this->maskEmail($email);
            $line = sprintf(
                '[%s] login-gagal | email:%s | sebab:%s | ip:%s',
                now()->format('Y-m-d H:i:s'),
                $masked,
                str_replace(["\r", "\n"], ' ', $reason),
                str_replace(["\r", "\n"], ' ', $ip)
            );
            $file = storage_path('logs' . DIRECTORY_SEPARATOR . 'auth-fail-' . now()->format('Y-m-d') . '.log');
            @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Logging tidak boleh menggagalkan respons login.
        }
    }

    /** Samarkan email: aw***@domain.tld; fallback '-' bila tidak valid. */
    private function maskEmail(string $email): string
    {
        $email = trim(str_replace(["\r", "\n"], ' ', $email));
        $parts = explode('@', $email);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return '-';
        }
        [$local, $domain] = $parts;
        $head = mb_substr($local, 0, 2);
        return $head . '***@' . $domain;
    }
}
