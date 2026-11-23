@extends('layouts.tabler')

@section('title', 'Profil Saya')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-7">
        @if(session('success'))
            <div class="alert alert-success">
                {{ session('success') }}
            </div>
        @endif

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Profil Saya</h3>
            </div>
            <div class="card-body">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <span class="avatar avatar-lg">
                        {{ strtoupper(substr($user->name ?? 'A', 0, 1)) }}
                    </span>
                    <div>
                        <div class="fw-bold">{{ $user->name }}</div>
                        <div class="text-secondary small">
                            {{ $user->email }} &middot; {{ $user->role ?? '-' }}
                        </div>
                    </div>
                </div>

                <form method="POST" action="{{ route('profile.update') }}">
                    @csrf
                    @method('PUT')

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="profile-name">
                                Nama <span class="text-danger">*</span>
                            </label>
                            <input
                                id="profile-name"
                                type="text"
                                name="name"
                                class="form-control @error('name') is-invalid @enderror"
                                value="{{ old('name', $user->name) }}"
                                required
                                maxlength="255"
                            >
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="profile-phone">
                                No. HP
                            </label>
                            <input
                                id="profile-phone"
                                type="text"
                                name="phone"
                                class="form-control @error('phone') is-invalid @enderror"
                                value="{{ old('phone', $user->phone) }}"
                                maxlength="50"
                                placeholder="08xxxxxxxxxx"
                            >
                            @error('phone')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Email (tidak bisa diubah di sini)</label>
                        <input
                            type="email"
                            class="form-control"
                            value="{{ $user->email }}"
                            disabled
                        >
                    </div>

                    <hr class="my-3">

                    <h4 class="mb-1">Ganti Password</h4>
                    <p class="text-secondary small mt-0 mb-3">
                        Kosongkan ketiga kolom di bawah bila tidak ingin ganti password.
                    </p>

                    <div class="mb-3">
                        <label class="form-label" for="profile-current">
                            Password Lama
                        </label>
                        <input
                            id="profile-current"
                            type="password"
                            name="current_password"
                            class="form-control @error('current_password') is-invalid @enderror"
                            autocomplete="current-password"
                        >
                        @error('current_password')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="profile-password">
                                Password Baru (min. 6)
                            </label>
                            <input
                                id="profile-password"
                                type="password"
                                name="password"
                                class="form-control @error('password') is-invalid @enderror"
                                autocomplete="new-password"
                                minlength="6"
                            >
                            @error('password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="profile-confirm">
                                Konfirmasi Password Baru
                            </label>
                            <input
                                id="profile-confirm"
                                type="password"
                                name="password_confirmation"
                                class="form-control"
                                autocomplete="new-password"
                                minlength="6"
                            >
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            Simpan Profil
                        </button>
                        <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary">
                            Kembali
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
