@extends('layouts.tabler')
@section('title','Kas')
@section('content')
<div class="page-header d-print-none"><div class="row align-items-center">
  <div class="col"><div class="page-pretitle">Keuangan</div><h2 class="page-title">Kas Toko</h2><div class="text-secondary">Saldo per akun & mutasi kas</div></div>
  <div class="col-auto d-flex gap-2"><button class="btn btn-outline-secondary" onclick="window.print()"><i class="ti ti-printer me-1"></i> Cetak</button>
  <a href="{{ route('cash.index', array_merge(request()->query(), ['export' => 'csv'])) }}" class="btn btn-outline-success"><i class="ti ti-download me-1"></i> Export CSV</a>
  <a href="{{ route('cash.flow') }}" class="btn btn-outline-primary"><i class="ti ti-trending-up me-1"></i> Arus Kas</a>
  <a href="{{ route('cash.closing') }}" class="btn btn-primary">Tutup Kasir / Handover</a></div>
</div></div>

<div class="row row-cards mb-3 print-area">
  @foreach($accounts as $a)
  <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body">
    <div class="text-secondary">{{ $a->name }} @if($a->is_default)<span class="badge bg-blue-lt">Default</span>@endif</div>
    <div class="h2 mt-1">Rp {{ number_format($a->balance,0,',','.') }}</div>
  </div></div></div>
  @endforeach
  <div class="col-sm-6 col-lg-3"><div class="card bg-green-lt"><div class="card-body">
    <div class="text-secondary">Total Masuk</div><div class="h2 mt-1">Rp {{ number_format($totalMasuk,0,',','.') }}</div>
  </div></div></div>
  <div class="col-sm-6 col-lg-3"><div class="card bg-red-lt"><div class="card-body">
    <div class="text-secondary">Total Keluar</div><div class="h2 mt-1">Rp {{ number_format($totalKeluar,0,',','.') }}</div>
  </div></div></div>
</div>

<div class="row row-cards">
<div class="col-lg-4"><div class="card"><div class="card-header"><h3 class="card-title">Kas Masuk / Keluar</h3></div>
<div
    class="card-body"
>
    <form
        method="POST"
        action="{{ route('cash.store') }}"
        enctype="multipart/form-data"
        onsubmit="return confirm('Simpan mutasi kas ini?')"
    >
        @csrf
        <div class="mb-2">
            <label class="form-label">Akun Kas</label>
            <select
                name="cash_account_id"
                class="form-select"
                required
            >
                @foreach($accounts as $a)
                    <option value="{{ $a->id }}">{{ $a->name }} (Rp {{ number_format($a->balance,0,',','.') }})</option>
                @endforeach
            </select>
        </div>
        <div class="row">
            <div class="col-6 mb-2">
                <label class="form-label">Tipe</label>
                <select
                    name="type"
                    class="form-select"
                    required
                >
                    <option value="masuk">Masuk</option>
                    <option value="keluar">Keluar</option>
                </select>
            </div>
            <div class="col-6 mb-2">
                <label class="form-label">Kategori</label>
                <select
                    name="category"
                    class="form-select"
                    required
                >
                    @foreach($categories as $c)
                        <option value="{{ $c }}">{{ ucfirst($c) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="mb-2">
            <label class="form-label">Nominal</label>
            <input
                type="number"
                name="amount"
                class="form-control"
                min="1"
                required
            >
        </div>
        <div class="mb-2">
            <label class="form-label">Keterangan</label>
            <textarea
                name="description"
                class="form-control"
                rows="2"
            ></textarea>
        </div>
        <div class="mb-3">
            <label class="form-label">
                Foto bukti (opsional, JPG/PNG maks 2MB)
            </label>
            <input
                name="bukti"
                type="file"
                accept="image/*"
                class="form-control"
            >
            <div class="form-hint mt-1">
                Disimpan sebagai marker [BUKTI:path] di keterangan, tanpa kolom baru.
            </div>
        </div>
        <button class="btn btn-primary w-100">Simpan</button>
    </form>
</div></div>

<div class="card mt-3"><div class="card-header"><h3 class="card-title">Transfer Antar Akun</h3></div>
<div class="card-body"><form method="POST" action="{{ route('cash.transfer') }}" onsubmit="return confirm('Proses transfer antar akun kas ini?')">@csrf
  <div class="mb-2"><label class="form-label">Dari Akun</label>
    <select name="from_account_id" class="form-select" required>@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }} (Rp {{ number_format($a->balance,0,',','.') }})</option>@endforeach</select></div>
  <div class="mb-2"><label class="form-label">Ke Akun</label>
    <select name="to_account_id" class="form-select" required>@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }} (Rp {{ number_format($a->balance,0,',','.') }})</option>@endforeach</select></div>
  <div class="mb-2"><label class="form-label">Nominal</label><input type="number" name="amount" class="form-control" min="1" required></div>
  <div class="mb-2"><label class="form-label">Catatan</label><textarea name="note" class="form-control" rows="2" placeholder="Opsional"></textarea></div>
  <button class="btn btn-dark w-100">Transfer</button>
</form></div></div>

<div class="card mt-3">
  <div class="card-header">
    <h3 class="card-title">Kelola Akun Kas</h3>
  </div>
  <div
    class="card-body"
  >
    <form
      method="POST"
      action="{{ route('cash.accounts.store') }}"
      onsubmit="return confirm('Tambah akun kas baru ini?')"
    >
      @csrf
      <div class="mb-2">
        <label class="form-label">Nama Akun Baru</label>
        <input
          type="text"
          name="name"
          class="form-control"
          maxlength="100"
          placeholder="cth: Kas Bank BCA"
          required
        >
      </div>
      <div class="mb-2">
        <label class="form-label">Saldo Awal (opsional)</label>
        <input
          type="number"
          name="opening_balance"
          class="form-control"
          min="0"
          step="any"
          value="0"
        >
        <div class="form-hint mt-1">
          Bila &gt; 0, dicatat sebagai kas MASUK kategori modal "Saldo awal {nama}" agar saldo = jumlah mutasi.
        </div>
      </div>
      <button class="btn btn-success w-100">Tambah Akun</button>
    </form>
    <hr>
    @foreach($accounts as $a)
      <form
        method="POST"
        action="{{ route('cash.accounts.update', $a->id) }}"
        onsubmit="return confirm('Simpan perubahan akun ini?')"
        class="mb-3"
      >
        @csrf
        @method('PUT')
        <div class="mb-1">
          <label class="form-label">
            Akun #{{ $a->id }} — Rp {{ number_format($a->balance,0,',','.') }}
            @if($a->is_default)
              <span class="badge bg-blue-lt">Default</span>
            @endif
          </label>
          <div class="input-group">
            <input
              type="text"
              name="name"
              class="form-control"
              maxlength="100"
              value="{{ $a->name }}"
              required
            >
            <button class="btn btn-outline-primary">Simpan</button>
          </div>
        </div>
        <label class="form-check">
          <input
            type="checkbox"
            name="is_default"
            class="form-check-input"
            value="1"
            {{ $a->is_default ? 'checked disabled' : '' }}
          >
          <span class="form-check-label">
            {{ $a->is_default ? 'Akun default saat ini' : 'Jadikan default' }}
          </span>
        </label>
      </form>
    @endforeach
    <div class="form-hint">
      Tabel cash_accounts tidak punya kolom is_active — penonaktifan akun dilewati tanpa migration.
    </div>
  </div>
</div>
</div>

<div class="col-lg-8"><div class="card print-area"><div class="card-header"><h3 class="card-title">Mutasi</h3>
<form
    class="ms-auto d-flex gap-2"
    method="GET"
    id="cashFilterForm"
>
  <input
    name="q"
    value="{{ request('q') }}"
    class="form-control form-control-sm"
    placeholder="Cari keterangan"
  >
  <select
    name="akun"
    class="form-select form-select-sm"
    title="Filter akun kas"
  >
    <option value="">
      Semua akun
    </option>
    @foreach($accounts as $a)
      <option
        value="{{ $a->id }}"
        {{ (string) request('akun') === (string) $a->id ? 'selected' : '' }}
      >
        {{ $a->name }}
      </option>
    @endforeach
  </select>
  <select name="type" class="form-select form-select-sm"><option value="">Semua tipe</option><option value="masuk" {{ request('type')=='masuk'?'selected':'' }}>Masuk</option><option value="keluar" {{ request('type')=='keluar'?'selected':'' }}>Keluar</option></select>
  <select name="kategori" class="form-select form-select-sm"><option value="">Semua kategori</option>@foreach($categories as $c)<option value="{{ $c }}" {{ request('kategori')==$c?'selected':'' }}>{{ ucfirst($c) }}</option>@endforeach</select>
  <input type="month" name="bulan" value="{{ request('bulan') }}" class="form-control form-control-sm">
  <button class="btn btn-sm btn-outline-primary">Filter</button>
  <span
    class="d-flex gap-1"
    role="group"
    aria-label="Preset tanggal cepat"
  >
    <button
      type="button"
      class="btn btn-sm btn-outline-primary"
      data-preset="today"
    >
      Hari ini
    </button>
    <button
      type="button"
      class="btn btn-sm btn-outline-primary"
      data-preset="week"
    >
      7 Hari
    </button>
    <button
      type="button"
      class="btn btn-sm btn-outline-primary"
      data-preset="month"
    >
      Bulan ini
    </button>
  </span>
</form></div>
<div class="card-body border-bottom d-print-none">
  <form id="bulkVoucherBar" class="d-flex flex-wrap align-items-end gap-2" onsubmit="return false;">
    <button type="button" class="btn btn-sm btn-outline-primary" id="printSelectedVouchersBtn">
      <i class="ti ti-printer me-1"></i> Cetak Terpilih (maks 50)
    </button>
    <span class="form-hint mt-1 w-100">
      Centang baris mutasi lalu cetak — voucher masuk/keluar digabung dalam 1 halaman cetak.
    </span>
  </form>
</div>
<div class="card-body border-bottom">
  <div class="alert alert-info mb-0">
    <div class="fw-bold">
      Cara baca kolom Saldo Berjalan
    </div>
    <div>
      Rumus per baris: saldo awal akun + jumlah berjalan
      (masuk − keluar) dari mutasi tertua sampai baris itu.
    </div>
    <div>
      Saldo awal akun = saldo kini − (total masuk − total keluar
      sepanjang waktu).
    </div>
    @if($singleAccount && $selectedAccount)
      <div>
        Mode akun tunggal: {{ $selectedAccount->name }} — angka ini
        akurat sebagai posisi saldo akun sesaat setelah tiap mutasi.
        Saldo awal hitungan: Rp {{ number_format($selectedOpening ?? 0, 0, ',', '.') }}.
      </div>
    @else
      <div>
        Mode semua akun: angka = saldo berjalan akun pada baris itu
        (per-akun), bukan total gabungan semua akun. Pilih filter akun
        untuk melihat riwayat saldo satu akun yang akurat.
      </div>
    @endif
    <div>
      Filter tipe / kategori / pencarian / bulan hanya menyaring
      tampilan — angka tetap dihitung dari seluruh riwayat sehingga
      tidak berubah walau filter aktif.
    </div>
    <div class="text-secondary">
      Batasan: akun lama yang saldo awalnya ditulis langsung ke saldo
      tanpa jurnal "Saldo awal …" membuat angka awal bergeser sebesar
      selisih itu; urutan kronologis memakai nomor mutasi; transfer
      antar akun tercatat 2 baris (keluar di akun asal, masuk di akun
      tujuan) yang masing-masing memengaruhi saldo akunnya sendiri.
    </div>
  </div>
</div>
<div class="table-responsive">
  <table class="table table-vcenter card-table">
    <thead>
      <tr>
        <th class="w-1 d-print-none">
          <input
            type="checkbox"
            class="form-check-input"
            id="checkAllCash"
            title="Pilih semua di halaman ini"
          >
        </th>
        <th>
          Tgl
        </th>
        <th>
          Akun
        </th>
        <th>
          Tipe
        </th>
        <th>
          Kategori
        </th>
        <th class="text-end">
          Nominal
        </th>
        <th class="text-end">
          Saldo Akun
          <span class="text-secondary fw-normal">
            (berjalan)
          </span>
        </th>
        <th>
          Keterangan
        </th>
        <th class="d-print-none">
          Aksi
        </th>
      </tr>
    </thead>
<tbody>
    @forelse($tx as $t)
        @php
            $buktiKasPath = null;
            if (preg_match('/\[BUKTI:(.*?)\]/', $t->description ?? '', $mKas)) {
                $buktiKasPath = trim($mKas[1]);
            }
            $descKasBersih = trim(preg_replace('/\[BUKTI:.*?\]/', '', $t->description ?? ''));
        @endphp
        <tr>
            <td class="d-print-none">
                <input
                    type="checkbox"
                    class="form-check-input cash-check"
                    value="{{ $t->id }}"
                >
            </td>
            <td>{{ $t->created_at->format('d/m/Y H:i') }}</td>
            <td>{{ $t->cashAccount->name ?? '-' }}</td>
            <td>
                <span class="badge bg-{{ $t->type=='masuk'?'green':'red' }}-lt">{{ $t->type }}</span>
            </td>
            <td>{{ $t->category }}</td>
            <td class="text-end">
              Rp {{ number_format($t->amount,0,',','.') }}
            </td>
            <td class="text-end">
              <div>
                Rp {{ number_format($runningBalances[$t->id] ?? 0,0,',','.') }}
              </div>
              @unless($singleAccount)
                <div class="text-secondary small">
                  per-akun
                </div>
              @endunless
            </td>
            <td class="text-secondary">
                @if($descKasBersih)
                    <div>{{ $descKasBersih }}</div>
                @else
                    <span class="text-secondary">—</span>
                @endif
                @if($buktiKasPath)
                    <div class="mt-1">
                        <a
                            href="{{ asset('storage/' . $buktiKasPath) }}"
                            target="_blank"
                        >
                            <img
                                src="{{ asset('storage/' . $buktiKasPath) }}"
                                alt="Bukti kas #{{ $t->id }}"
                                class="rounded border"
                                style="width: 64px; height: 64px; object-fit: cover;"
                                loading="lazy"
                            >
                        </a>
                        <div>
                            <a
                                href="{{ asset('storage/' . $buktiKasPath) }}"
                                target="_blank"
                                class="small"
                            >
                                Lihat bukti
                            </a>
                        </div>
                    </div>
                @endif
            </td>
            <td class="d-print-none">
                <a
                    href="{{ route('cash.voucher', $t->id) }}"
                    class="btn btn-sm btn-outline-primary"
                >
                    <i class="ti ti-receipt me-1"></i> Voucher
                </a>
            </td>
        </tr>
    @empty<tr><td colspan="9"><div class="empty py-3"><div class="empty-icon"><i class="ti ti-wallet fs-1 text-secondary"></i></div><p class="empty-title">Belum ada mutasi</p><p class="empty-subtitle text-secondary">Mutasi tercatat otomatis dari penjualan/service, atau catat manual lewat formulir Kas Masuk/Keluar.</p></div></td></tr>@endforelse</tbody></table></div>
<div class="card-footer">{{ $tx->links() }}</div></div></div>
</div>
<script>
document.getElementById('checkAllCash')?.addEventListener('change', function () {
    document.querySelectorAll('.cash-check').forEach(function (cb) {
        cb.checked = document.getElementById('checkAllCash').checked;
    });
});
document.getElementById('printSelectedVouchersBtn')?.addEventListener('click', function () {
    const ids = Array.from(document.querySelectorAll('.cash-check:checked')).map(function (cb) {
        return cb.value;
    });
    if (!ids.length) {
        alert('Pilih minimal 1 mutasi kas dulu (centang baris tabel).');
        return;
    }
    if (ids.length > 50) {
        alert('Maksimal 50 voucher per cetak massal. Kurangi pilihan Anda.');
        return;
    }
    const q = ids.map(function (id) {
        return 'ids[]=' + encodeURIComponent(id);
    }).join('&');
    window.open("{{ route('cash.vouchers') }}?" + q, '_blank');
});
(function () {
    var form = document.getElementById('cashFilterForm');
    if (!form) {
        return;
    }
    function fmtMonth(d) {
        var m = String(d.getMonth() + 1).padStart(2, '0');
        return d.getFullYear() + '-' + m;
    }
    function applyMonth(val) {
        var bulanEl = form.querySelector('input[name="bulan"]');
        if (bulanEl) {
            bulanEl.value = val;
        }
        form.submit();
    }
    form.querySelectorAll('[data-preset]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var now = new Date();
            applyMonth(fmtMonth(now));
        });
    });
})();
</script>
@endsection
