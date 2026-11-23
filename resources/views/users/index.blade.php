@extends('layouts.tabler')
@section('title','Pengguna')
@section('content')
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <div class="page-pretitle">
                Master
            </div>
            <h2 class="page-title">
                Pengguna
            </h2>
        </div>
        <div class="col-auto d-flex flex-wrap gap-2">
            <a
                href="{{ route('users.index', array_merge(request()->query(), ['export' => 'csv'])) }}"
                class="btn btn-outline-secondary"
            >
                Export CSV
            </a>
            <a
                href="{{ route('users.create') }}"
                class="btn btn-primary"
            >
                + Pengguna
            </a>
        </div>
    </div>
</div>
<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center">
        <form
            class="d-flex gap-2"
            method="GET"
        >
            <input
                name="q"
                value="{{ request('q') }}"
                class="form-control"
                placeholder="Cari nama / email"
            >
            <button class="btn btn-outline-primary">
                Cari
            </button>
        </form>
        <form
            id="bulkUserForm"
            action="{{ route('users.bulkStatus') }}"
            method="POST"
            class="d-flex flex-wrap gap-2 align-items-center ms-auto"
        >
            @csrf
            <select
                name="action"
                id="bulkStatusSelect"
                class="form-select"
                style="max-width: 170px;"
            >
                <option
                    value=""
                    disabled
                    selected
                >
                    Aksi massal…
                </option>
                <option value="active">
                    Aktifkan
                </option>
                <option value="inactive">
                    Nonaktifkan
                </option>
            </select>
            <button
                type="submit"
                class="btn btn-outline-secondary"
                onclick="return confirmBulkUser(event, 'status')"
            >
                Terapkan
            </button>
            <select
                name="role"
                id="bulkRoleSelect"
                class="form-select"
                style="max-width: 170px;"
            >
                <option
                    value=""
                    disabled
                    selected
                >
                    Set role…
                </option>
                @foreach(['superadmin', 'owner', 'admin', 'kasir', 'teknisi', 'gudang'] as $r)
                    <option value="{{ $r }}">
                        {{ ucfirst($r) }}
                    </option>
                @endforeach
            </select>
            <button
                type="submit"
                class="btn btn-outline-primary"
                formaction="{{ route('users.bulkRole') }}"
                onclick="return confirmBulkUser(event, 'role')"
            >
                Set Role Terpilih
            </button>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead>
                <tr>
                    <th style="width: 36px;">
                        <input
                            type="checkbox"
                            id="checkAllUser"
                            class="form-check-input"
                        >
                    </th>
                    <th>
                        Nama
                    </th>
                    <th>
                        Email
                    </th>
                    <th>
                        Role
                    </th>
                    <th>
                        Toko
                    </th>
                    <th>
                        Status
                    </th>
                    <th>
                        Transaksi Terakhir
                    </th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($users as $u)
                    <tr>
                        <td>
                            <input
                                type="checkbox"
                                name="ids[]"
                                value="{{ $u->id }}"
                                form="bulkUserForm"
                                class="form-check-input user-check"
                                @disabled((int) $u->id === (int) auth()->id())
                                title="{{ (int) $u->id === (int) auth()->id() ? 'Akun sendiri dikecualikan dari aksi massal' : 'Pilih pengguna' }}"
                            >
                        </td>
                        <td>
                            {{ $u->name }}
                        </td>
                        <td>
                            {{ $u->email }}
                        </td>
                        <td>
                            <span class="badge bg-blue-lt">
                                {{ $u->role }}
                            </span>
                        </td>
                        <td class="text-secondary small">
                            {{ $u->stores->pluck('name')->join(', ') ?: '-' }}
                        </td>
                        <td>
                            <span class="badge bg-{{ $u->is_active ? 'green' : 'red' }}-lt">
                                {{ $u->is_active ? 'Aktif' : 'Nonaktif' }}
                            </span>
                        </td>
                        <td class="text-secondary small">
                            @if($u->last_sale)
                                Jual: {{ $u->last_sale->invoice_no }} ({{ $u->last_sale->created_at->format('d/m/y') }})
                                <br>
                            @endif
                            @if($u->last_service)
                                Service: {{ $u->last_service->ticket_no }} ({{ $u->last_service->created_at->format('d/m/y') }})
                            @endif
                            @if(!$u->last_sale && !$u->last_service)
                                -
                            @endif
                        </td>
                        <td class="text-end">
                            <a
                                href="{{ route('users.show', $u) }}"
                                class="btn btn-sm btn-outline-primary"
                            >
                                Detail
                            </a>
                            <a
                                href="{{ route('users.edit', $u) }}"
                                class="btn btn-sm btn-outline-secondary"
                            >
                                Edit
                            </a>
                        </td>
                    </tr>
                @endforeach
                @if($users->isEmpty())
                    <tr>
                        <td colspan="8">
                            <div class="empty py-3">
                                <div class="empty-icon">
                                    <i class="ti ti-user-check fs-1 text-secondary"></i>
                                </div>
                                <p class="empty-title">
                                    Belum ada pengguna
                                </p>
                                <p class="empty-subtitle text-secondary">
                                    Tambahkan kasir, teknisi, atau admin dan atur toko yang bisa diakses.
                                </p>
                                <div class="empty-action">
                                    <a
                                        href="{{ route('users.create') }}"
                                        class="btn btn-primary"
                                    >
                                        <i class="ti ti-plus me-1"></i>
                                        Tambah Pengguna
                                    </a>
                                </div>
                            </div>
                        </td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>
    <div class="card-footer">
        {{ $users->links() }}
    </div>
</div>
<script>
    document
        .getElementById('checkAllUser')
        ?.addEventListener('change', function () {
            var checked = this.checked;
            document
                .querySelectorAll('.user-check:not(:disabled)')
                .forEach(function (box) {
                    box.checked = checked;
                });
        });
    function confirmBulkUser(e, mode) {
        var picked = document.querySelectorAll('.user-check:checked:not(:disabled)').length;
        if (!picked) {
            alert('Pilih minimal 1 pengguna.');
            e.preventDefault();
            return false;
        }
        if (mode === 'status') {
            if (!document.getElementById('bulkStatusSelect').value) {
                alert('Pilih aksi status terlebih dahulu.');
                e.preventDefault();
                return false;
            }
            return confirm('Terapkan status ke pengguna terpilih? Akun sendiri dilewati, minimal 1 superadmin aktif tersisa.');
        }
        if (!document.getElementById('bulkRoleSelect').value) {
            alert('Pilih role tujuan terlebih dahulu.');
            e.preventDefault();
            return false;
        }
        return confirm('Ubah role pengguna terpilih? Akun sendiri dilewati, superadmin terakhir dilindungi.');
    }
</script>
@endsection
