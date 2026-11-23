<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /** Hanya owner/superadmin (dan admin) yang boleh kelola pengguna. */
    private function authorizeManager(): void
    {
        $role = auth()->user()?->role;
        if (!in_array($role, ['superadmin', 'owner', 'admin'], true)) {
            abort(403, 'Hanya owner/superadmin yang boleh mengelola pengguna.');
        }
    }

    public function index(Request $request)
    {
        $this->authorizeManager();
        // Export CSV (?export=csv) — hormati filter q aktif, delimiter ; + BOM.
        // Tanpa password/hash: hanya nama, email, role, hp, toko, aktif.
        if ($request->get('export') === 'csv') {
            $rows = User::with('stores')
                ->when($request->filled('q'), fn ($q) => $q->where(function ($w) use ($request) {
                    $w->where('name', 'like', '%' . $request->q . '%')->orWhere('email', 'like', '%' . $request->q . '%');
                }))
                ->orderBy('name')->limit(5000)->get();
            $filename = 'pengguna-' . now()->format('Ymd-His') . '.csv';
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['nama', 'email', 'role', 'hp', 'toko', 'aktif'], ';');
                foreach ($rows as $u) {
                    fputcsv($out, [
                        $u->name,
                        $u->email,
                        $u->role,
                        $u->phone ?? '',
                        $u->stores->pluck('name')->join(', '),
                        $u->is_active ? 'Aktif' : 'Nonaktif',
                    ], ';');
                }
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        $users = User::with('stores')
            ->when($request->filled('q'), fn ($q) => $q->where(function ($w) use ($request) {
                $w->where('name', 'like', '%' . $request->q . '%')->orWhere('email', 'like', '%' . $request->q . '%');
            }))
            ->orderBy('name')->paginate(20)->withQueryString();

        // Aktivitas sederhana tanpa tabel baru: transaksi terakhir (sale/service) per user
        try {
            $uids = $users->pluck('id')->all();
            $lastSales = \App\Models\Sale::whereIn('user_id', $uids)
                ->selectRaw('user_id, MAX(id) as max_id')->groupBy('user_id')->pluck('max_id', 'user_id');
            $lastServices = \App\Models\ServiceTicket::whereIn('technician_id', $uids)
                ->selectRaw('technician_id as user_id, MAX(id) as max_id')->groupBy('technician_id')->pluck('max_id', 'user_id');
            $salesById = \App\Models\Sale::whereIn('id', $lastSales->values()->all())->get()->keyBy('id');
            $servicesById = \App\Models\ServiceTicket::whereIn('id', $lastServices->values()->all())->get()->keyBy('id');
            foreach ($users as $u) {
                $u->last_sale = isset($lastSales[$u->id]) ? ($salesById[$lastSales[$u->id]] ?? null) : null;
                $u->last_service = isset($lastServices[$u->id]) ? ($servicesById[$lastServices[$u->id]] ?? null) : null;
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return view('users.index', compact('users'));
    }

    public function create()
    {
        $this->authorizeManager();
        $stores = Store::where('is_active', true)->orderBy('name')->get();
        return view('users.create', compact('stores'));
    }

    public function store(Request $request)
    {
        $this->authorizeManager();
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'role' => 'required|in:superadmin,owner,admin,kasir,teknisi,gudang',
            'phone' => 'nullable|string|max:50',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'store_ids' => 'nullable|array',
            'store_ids.*' => 'exists:stores,id',
            'default_store_id' => 'nullable|exists:stores,id',
        ]);

        DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role' => $data['role'],
                'phone' => $data['phone'] ?? null,
                'commission_rate' => $data['commission_rate'] ?? 0,
                'active_store_id' => $data['default_store_id'] ?? ($data['store_ids'][0] ?? null),
                'is_active' => true,
            ]);
            if (!empty($data['store_ids'])) {
                $pivot = [];
                foreach ($data['store_ids'] as $sid) {
                    $pivot[$sid] = ['is_default' => (int) $sid === (int) ($data['default_store_id'] ?? 0)];
                }
                $user->stores()->sync($pivot);
            }
        });

        return redirect()->route('users.index')->with('success', 'Pengguna dibuat.');
    }

    public function show(User $user)
    {
        $this->authorizeManager();
        $user->load(['stores', 'activeStore']);
        return view('users.show', compact('user'));
    }

    public function edit(User $user)
    {
        $this->authorizeManager();
        $stores = Store::where('is_active', true)->orderBy('name')->get();
        $user->load('stores');
        return view('users.edit', compact('user', 'stores'));
    }

    public function update(Request $request, User $user)
    {
        $this->authorizeManager();
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:6',
            'role' => 'required|in:superadmin,owner,admin,kasir,teknisi,gudang',
            'phone' => 'nullable|string|max:50',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'is_active' => 'nullable|boolean',
            'store_ids' => 'nullable|array',
            'store_ids.*' => 'exists:stores,id',
            'default_store_id' => 'nullable|exists:stores,id',
        ]);

        DB::transaction(function () use ($user, $data) {
            $user->update([
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => $data['role'],
                'phone' => $data['phone'] ?? null,
                'commission_rate' => $data['commission_rate'] ?? 0,
                'is_active' => (bool) ($data['is_active'] ?? false),
                'active_store_id' => $data['default_store_id'] ?? $user->active_store_id,
                ...(filled($data['password'] ?? null) ? ['password' => Hash::make($data['password'])] : []),
            ]);
            if (array_key_exists('store_ids', $data)) {
                $pivot = [];
                foreach (($data['store_ids'] ?? []) as $sid) {
                    $pivot[$sid] = ['is_default' => (int) $sid === (int) ($data['default_store_id'] ?? 0)];
                }
                $user->stores()->sync($pivot);
            }
        });

        return redirect()->route('users.index')->with('success', 'Pengguna diperbarui (reset password bila diisi, assign toko tersimpan).');
    }

    /** Profil sendiri: semua role yang login boleh akses (tanpa authorizeManager). */
    public function profile()
    {
        $user = auth()->user();

        return view('users.profile', compact('user'));
    }

    /** Update profil sendiri: nama + HP + ganti password (wajib password lama + konfirmasi). */
    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'current_password' => 'nullable|required_with:password|current_password',
            'password' => 'nullable|string|min:6|confirmed',
        ], [
            'current_password.required_with' => 'Password lama wajib diisi untuk ganti password.',
            'current_password.current_password' => 'Password lama salah.',
            'password.confirmed' => 'Konfirmasi password baru tidak cocok.',
        ]);

        $user = $request->user();
        $user->name = $data['name'];
        $user->phone = $data['phone'] ?? null;
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        return back()->with(
            'success',
            ! empty($data['password']) ? 'Profil & password diperbarui.' : 'Profil diperbarui.'
        );
    }

    public function bulkRole(Request $request)
    {
        $this->authorizeManager();
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'exists:users,id',
            'role' => 'required|in:superadmin,owner,admin,kasir,teknisi,gudang',
        ]);

        // Cegah ubah diri sendiri: keluarkan akun sendiri dari target.
        $ids = collect($data['ids'])->map(fn ($v) => (int) $v)->unique()->values();
        $selfSkipped = $ids->contains((int) auth()->id()) ? 1 : 0;
        $ids = $ids->reject(fn ($id) => $id === (int) auth()->id())->values();

        if ($ids->isEmpty()) {
            return back()->with('error', 'Tidak ada target valid (akun sendiri tidak boleh diubah).');
        }

        // Cegah hilangnya superadmin terakhir bila target role bukan superadmin.
        if ($data['role'] !== 'superadmin') {
            $totalSuperadmin = User::where('role', 'superadmin')->count();
            $affectedSuperadmin = User::whereIn('id', $ids->all())
                ->where('role', 'superadmin')
                ->count();
            if ($totalSuperadmin - $affectedSuperadmin < 1) {
                return back()->with('error', 'Ditolak: perubahan ini akan menghilangkan superadmin terakhir.');
            }
        }

        $updated = User::whereIn('id', $ids->all())->update(['role' => $data['role']]);

        $msg = "Role {$updated} pengguna diubah ke {$data['role']}.";
        if ($selfSkipped) {
            $msg .= ' (Akun sendiri dilewati.)';
        }
        return back()->with('success', $msg);
    }

    public function destroy(User $user)
    {
        $this->authorizeManager();
        if ((int) $user->id === (int) auth()->id()) {
            return back()->with('error', 'Tidak bisa menonaktifkan akun sendiri.');
        }
        $user->update(['is_active' => false]);
        return back()->with('success', 'Pengguna dinonaktifkan.');
    }

    /** Aktif/nonaktif massal. Guard: cegah diri sendiri + minimal 1 superadmin aktif tersisa. */
    public function bulkStatus(Request $request)
    {
        $this->authorizeManager();
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'exists:users,id',
            'action' => 'required|in:active,inactive',
        ]);

        $ids = array_map('intval', $data['ids']);
        $activate = $data['action'] === 'active';
        $skippedSelf = 0;

        if (! $activate && in_array((int) auth()->id(), $ids, true)) {
            $ids = array_values(array_diff($ids, [(int) auth()->id()]));
            $skippedSelf++;
        }

        if (empty($ids)) {
            return back()->with('error', 'Tidak bisa menonaktifkan akun sendiri.');
        }

        if (! $activate) {
            $activeSuperadmin = User::where('role', 'superadmin')
                ->where('is_active', true)
                ->count();
            $superadminToOff = User::whereIn('id', $ids)
                ->where('role', 'superadmin')
                ->where('is_active', true)
                ->count();
            if ($activeSuperadmin - $superadminToOff < 1) {
                return back()->with('error', 'Ditolak: minimal 1 superadmin aktif harus tersisa.');
            }
        }

        $affected = User::whereIn('id', $ids)
            ->update(['is_active' => $activate]);

        $msg = $activate
            ? "Berhasil mengaktifkan {$affected} pengguna."
            : "Berhasil menonaktifkan {$affected} pengguna.";
        if ($skippedSelf > 0) {
            $msg .= ' Akun sendiri dilewati.';
        }

        return back()->with('success', $msg);
    }
}
