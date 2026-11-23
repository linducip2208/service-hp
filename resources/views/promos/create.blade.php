@extends('layouts.tabler')
@section('title', 'Tambah Promo')
@section('content')
<div class="card col-lg-7 mx-auto">
    <div class="card-header">
        <h3 class="card-title">Promo Baru</h3>
    </div>
    <div class="card-body">
        @if (!empty($prefill['type']) && $prefill['type'] === 'bundling')
            <div class="alert alert-success py-2 small">
                <i class="ti ti-package me-1"></i> Prefill <b>bundling</b> dari pasangan:
                syarat ≥2 produk berbeda otomatis berlaku di POS.
                Sesuaikan kode, nilai %, dan min. belanja lalu simpan.
            </div>
        @endif
        <form method="POST" action="{{ route('promos.store') }}" data-loading>
            @csrf
            <div class="mb-2">
                <label class="form-label">Toko (kosongkan = semua toko)</label>
                <select name="store_id" class="form-select">
                    <option value="">— Semua toko —</option>
                    @foreach ($stores as $s)
                        <option value="{{ $s->id }}" {{ (string)old('store_id')===(string)$s->id?'selected':'' }}>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="row">
                <div class="col-4 mb-2">
                    <label class="form-label">Kode</label>
                    <div class="input-group">
                        <input
                            id="promo-code"
                            name="code"
                            class="form-control @error('code') is-invalid @enderror"
                            placeholder="WELCOME10"
                            value="{{ old('code', $prefill['code'] ?? '') }}"
                            maxlength="50"
                            required
                        >
                        <button
                            type="button"
                            id="promo-random"
                            class="btn btn-outline-secondary"
                            title="Buat kode acak"
                        >
                            Acak
                        </button>
                    </div>
                    @error('code')
                        <div class="invalid-feedback d-block">
                            {{ $message }}
                        </div>
                    @enderror
                    <div class="form-hint">
                        Huruf besar, tanpa spasi. Duplikat ditolak
                        (pesan "Kode promo sudah dipakai").
                    </div>
                </div>
                <div class="col-8 mb-2">
                    <label class="form-label">Nama</label>
                    <input
                        name="name"
                        class="form-control"
                        value="{{ old('name', $prefill['name'] ?? '') }}"
                        required
                    >
                </div>
            </div>
            <div class="row">
                <div class="col-4 mb-2">
                    <label class="form-label">Tipe</label>
                    <select name="type" id="promo-type" class="form-select">
                        @foreach (['diskon_persen', 'diskon_rupiah', 'bundling', 'voucher', 'member'] as $t)
                            <option
                                value="{{ $t }}"
                                {{ old('type', $prefill['type'] ?? '') === $t ? 'selected' : '' }}
                            >
                                {{ $t }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-4 mb-2">
                    <label class="form-label">Nilai</label>
                    <input
                        type="number"
                        name="value"
                        class="form-control"
                        min="0"
                        value="{{ old('value', $prefill['value'] ?? 0) }}"
                        required
                    >
                </div>
                <div class="col-4 mb-2">
                    <label class="form-label">Min. belanja</label>
                    <input
                        type="number"
                        name="min_belanja"
                        class="form-control"
                        min="0"
                        value="{{ old('min_belanja', $prefill['min_belanja'] ?? 0) }}"
                    >
                </div>
            </div>
            <div class="alert alert-info py-2 small" id="promo-hint"></div>
            <script>
                const PROMO_HINTS = {
                    diskon_persen: ' <b>diskon_persen</b>: Nilai = persen (0–100). Contoh: 10 = potongan 10% dari subtotal. Berlaku bila min. belanja + quota + periode terpenuhi.',
                    diskon_rupiah: ' <b>diskon_rupiah</b>: Nilai = rupiah. Contoh: 20000 = potongan Rp 20.000 (dicap maksimal subtotal). Berlaku bila min. belanja + quota + periode terpenuhi.',
                    voucher: ' <b>voucher</b>: Nilai = nominal rupiah, sekali pakai per transaksi (satu kode untuk satu struk). Tetap hormati min. belanja + quota + tanggal.',
                    member: ' <b>member</b>: Khusus pelanggan Silver (total belanja ≥ Rp 1 jt) / Gold (> Rp 5 jt). Nilai ≤100 dibaca persen, >100 dibaca rupiah. Tetap hormati min. belanja + quota + tanggal.',
                    bundling: '<i class="ti ti-package me-1"></i> <b>bundling</b>: Nilai = persen diskon (0–100) yang HANYA berlaku bila keranjang berisi ≥2 produk berbeda. Kolom yang ada belum menyimpan pasangan produk A/B, jadi aturan pasangan spesifik (mis. "beli A gratis B") belum didukung — syarat minimal 2 item berbeda + pesan jelas bila tak terpenuhi.',
                };
                const _pt = document.getElementById('promo-type');
                const _ph = document.getElementById('promo-hint');
                function _syncHint() {
                    _ph.innerHTML = PROMO_HINTS[_pt.value] || '';
                }
                _pt.addEventListener('change', _syncHint);
                _syncHint();
            </script>
            <script>
                (function () {
                    const codeInput = document.getElementById('promo-code');
                    const randomBtn = document.getElementById('promo-random');
                    const typeSelect = document.getElementById('promo-type');
                    if (!codeInput || !randomBtn) {
                        return;
                    }
                    // Charset mudah baca: hindari ambigu O/0/I/1.
                    const SAFE_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
                    const PREFIX_BY_TYPE = {
                        diskon_persen: 'DISC-',
                        diskon_rupiah: 'RP-',
                        bundling: 'BUND-',
                        voucher: 'VOUCH-',
                        member: 'MEM-',
                    };
                    function randomSuffix(len) {
                        let out = '';
                        const pool = window.crypto && window.crypto.getRandomValues
                            ? window.crypto.getRandomValues(new Uint32Array(len))
                            : Array.from(
                                { length: len },
                                () => Math.floor(Math.random() * 4294967296)
                            );
                        for (let i = 0; i < len; i++) {
                            out += SAFE_CHARS[pool[i] % SAFE_CHARS.length];
                        }
                        return out;
                    }
                    function currentPrefix() {
                        const t = typeSelect ? typeSelect.value : '';
                        return PREFIX_BY_TYPE[t] || '';
                    }
                    randomBtn.addEventListener('click', function () {
                        // Panjang acak 4–6 char (inklusif), default pola 5 char.
                        const len = 4 + Math.floor(Math.random() * 3);
                        codeInput.value = (currentPrefix() + randomSuffix(len))
                            .toUpperCase()
                            .replace(/\s+/g, '');
                        codeInput.focus();
                    });
                    // Normalisasi ringan: selalu huruf besar tanpa spasi.
                    codeInput.addEventListener('input', function () {
                        const pos = codeInput.selectionStart;
                        codeInput.value = codeInput.value
                            .toUpperCase()
                            .replace(/\s+/g, '');
                        try {
                            codeInput.setSelectionRange(pos, pos);
                        } catch (e) {
                            // Abaikan bila browser tidak dukung selection.
                        }
                    });
                })();
            </script>
            <div class="row">
                <div class="col-4 mb-2">
                    <label class="form-label">Mulai</label>
                    <input type="date" name="start_date" value="{{ old('start_date') }}" class="form-control">
                </div>
                <div class="col-4 mb-2">
                    <label class="form-label">Berakhir</label>
                    <input type="date" name="end_date" value="{{ old('end_date') }}" class="form-control">
                </div>
                <div class="col-4 mb-2">
                    <label class="form-label">Quota (0 = tanpa batas)</label>
                    <input type="number" name="quota" class="form-control" min="0" value="{{ old('quota', 0) }}">
                </div>
            </div>
            <label class="form-check">
                <input type="checkbox" name="is_active" value="1" class="form-check-input" {{ old('is_active', true)?'checked':'' }}>
                <span class="form-check-label">Aktif</span>
            </label>
            <div class="mt-2">
                <button class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>
@endsection
