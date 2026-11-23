@extends('layouts.tabler')
@section('title','Backup Data')
@section('content')
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <div class="page-pretitle">
                Master • Khusus superadmin/owner
            </div>
            <h2 class="page-title">
                <i class="ti ti-device-floppy me-1"></i> Backup Data
            </h2>
        </div>
        <div class="col-auto ms-auto d-flex gap-2">
            <a href="{{ route('backup.csv') }}" class="btn btn-outline-success">
                <i class="ti ti-download me-1"></i> Unduh Semua CSV (ZIP)
            </a>
            <form
                method="POST"
                action="{{ route('backup.clear-cache') }}"
                data-loading
                onsubmit="return confirm('Bersihkan cache aplikasi (view + cache + config)?')"
            >
                @csrf
                <button class="btn btn-outline-warning">
                    <i class="ti ti-folder me-1"></i> Bersihkan Cache Aplikasi
                </button>
            </form>
            <form
                method="POST"
                action="{{ route('backup.store') }}"
                data-loading
                onsubmit="return confirm('Buat backup sekarang?')"
            >
                @csrf
                <button class="btn btn-primary">
                    ＋ Buat Backup Sekarang
                </button>
            </form>
        </div>
    </div>
</div>

<div class="alert alert-info mt-3">
    <i class="ti ti-package me-1"></i> Backup memakai
    <b>mysqldump</b>
    bila tersedia (otomatis dicari di PATH & folder Laragon/XAMPP),
    atau
    <b>export JSON</b>
    per tabel penting
    (<code>stores, products, sales, service_tickets, customers, cash_transactions</code>)
    sebagai fallback.
    File tersimpan di
    <code>storage/app/backups</code>.
    Unduh & simpan salinannya di tempat aman secara berkala.
    Tombol
    <b>Verifikasi</b>
    hanya membaca file (read-only, tanpa restore):
    SQL dicek header mysqldump + jumlah CREATE TABLE/INSERT,
    JSON dicek validitas + jumlah kunci utama.
</div>

@if(session('verify_name'))
    <div class="alert alert-{{ session('verify_ok') ? 'success' : 'danger' }} mt-3">
        <strong>
            {{ session('verify_ok') ? '<i class="ti ti-check me-1"></i> Sehat' : '<i class="ti ti-x me-1"></i> Rusak' }}
        </strong>
        <code>{{ session('verify_name') }}</code>
        <br>
        <small>{{ session('verify_detail') }}</small>
    </div>
@endif

<div class="card mt-3">
    <div class="card-header">
        <h3 class="card-title">
            <i class="ti ti-flask me-1"></i> Data Demo Showroom (ON/OFF)
        </h3>
        <div class="card-actions">
            @if($demo['exists'])
                <span class="badge bg-green-lt">TERMUAT</span>
            @else
                <span class="badge bg-secondary-lt">KOSONG</span>
            @endif
            @if($demo['flag'])
                <span class="badge bg-blue-lt">flag demo_seeded ada</span>
            @endif
        </div>
    </div>
    <div class="card-body">
        <div class="alert alert-warning">
            <i class="ti ti-alert-triangle me-1"></i> <b>Wajib backup dulu</b> sebelum memakai fitur ini — klik
            “Buat Backup Sekarang” di atas dan unduh hasilnya.
            <b>Muat</b> menambah 15 penjualan + 8 tiket + 3 pembelian + 2 booking
            berprefix <code>DEMO-</code>.
            <b>Bersihkan</b> menghapus <b>SEMUA</b> baris <code>DEMO-*</code>
            di semua tabel (penjualan, tiket, pembelian, booking, kas, hutang/piutang,
            retur, garansi) + flag <code>demo_seeded</code>. Tidak bisa dibatalkan.
        </div>
        <div class="text-secondary small mb-3">
            Terdeteksi —
            penjualan: <b>{{ $demo['sales'] }}</b> •
            tiket: <b>{{ $demo['tickets'] }}</b> •
            pembelian: <b>{{ $demo['purchases'] }}</b> •
            booking: <b>{{ $demo['bookings'] }}</b> •
            kas demo: <b>{{ $demo['cash'] }}</b> •
            beban demo: <b>{{ $demo['expenses'] }}</b>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @if(!$demo['exists'])
                <form
                    method="POST"
                    action="{{ route('backup.demo-seed') }}"
                    data-loading
                    onsubmit="return confirm('Muat data demo showroom (prefix DEMO-)? Pastikan sudah backup dulu.')"
                >
                    @csrf
                    <button class="btn btn-success">
                        <i class="ti ti-flask me-1"></i> Muat Data Demo
                    </button>
                </form>
            @else
                <button class="btn btn-success" disabled title="Data demo sudah ada — hapus dulu untuk muat ulang">
                    <i class="ti ti-flask me-1"></i> Muat Data Demo (sudah ada)
                </button>
            @endif
            <form
                method="POST"
                action="{{ route('backup.demo-clean') }}"
                data-loading
                onsubmit="return confirm('PERINGATAN: hapus SEMUA data DEMO-*? Pastikan sudah backup! Lanjut?') && confirm('Konfirmasi kedua: benar-benar HAPUS SEMUA demo? Tindakan ini permanen.')"
            >
                @csrf
                <div class="d-flex flex-wrap align-items-center gap-2 border rounded p-2">
                    <label class="form-check mb-0">
                        <input type="checkbox" name="confirm" value="1" class="form-check-input" required>
                        <span class="form-check-label">Saya paham ini menghapus permanen & saya sudah backup</span>
                    </label>
                    <input
                        type="text"
                        name="confirm_text"
                        class="form-control form-control-sm"
                        style="max-width: 180px"
                        placeholder='Ketik: HAPUS DEMO'
                        required
                        autocomplete="off"
                    >
                    <button class="btn btn-sm btn-danger">
                        <i class="ti ti-trash me-1"></i> Bersihkan Demo
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="card mt-2">
    <div class="card-header">
        <h3 class="card-title">
            Daftar Backup ({{ $files->count() }})
        </h3>
    </div>
    <div class="table-responsive">
        <table class="table card-table table-vcenter">
            <thead>
                <tr>
                    <th>File</th>
                    <th>Tipe</th>
                    <th>Status</th>
                    <th>Ukuran</th>
                    <th>Tanggal</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($files as $f)
                    <tr>
                        <td>
                            <code>{{ $f['name'] }}</code>
                        </td>
                        <td>
                            <span class="badge bg-{{ $f['via'] === 'sql' ? 'green' : 'yellow' }}-lt">
                                {{ $f['type'] }}
                            </span>
                        </td>
                        <td>
                            @if(session('verify_name') === $f['name'])
                                <span class="badge bg-{{ session('verify_ok') ? 'green' : 'red' }}-lt">
                                    {{ session('verify_ok') ? 'Sehat' : 'Rusak' }}
                                </span>
                                <div class="text-secondary small mt-1">
                                    {{ session('verify_detail') }}
                                </div>
                            @else
                                <span class="text-secondary small">
                                    Belum dicek
                                </span>
                            @endif
                        </td>
                        <td>{{ $f['size_h'] }}</td>
                        <td>{{ $f['date'] }}</td>
                        <td class="text-end">
                            <div class="btn-list justify-content-end">
                                <a
                                    class="btn btn-sm btn-outline-info"
                                    href="{{ route('backup.verify', $f['name']) }}"
                                >
                                    <i class="ti ti-check me-1"></i> Verifikasi
                                </a>
                                <a
                                    class="btn btn-sm btn-outline-primary"
                                    href="{{ route('backup.download', $f['name']) }}"
                                >
                                    <i class="ti ti-download me-1"></i> Unduh
                                </a>
                                <form
                                    method="POST"
                                    action="{{ route('backup.destroy', $f['name']) }}"
                                    class="d-inline"
                                    onsubmit="return confirm('Hapus {{ $f['name'] }}?')"
                                >
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger">
                                        Hapus
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-secondary py-4">
                            Belum ada backup.
                            <br>
                            <small>Klik “Buat Backup Sekarang” untuk backup pertama.</small>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
