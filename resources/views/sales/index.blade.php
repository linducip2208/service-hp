@extends('layouts.tabler')
@section('title', 'Riwayat Transaksi')
@section('content')
<div class="page-header"><div class="row align-items-center"><div class="col"><div class="page-pretitle">Penjualan</div><h2 class="page-title">Riwayat Transaksi @if($showAll)<span class="badge bg-purple-lt">Semua toko</span>@endif</h2></div>
<div class="col-auto">
  <a href="{{ route('sales.closing') }}" class="btn btn-outline-warning">Tutup Kasir</a>
  <a href="{{ route('pos.index') }}" class="btn btn-primary">+ Transaksi Baru</a>
</div></div></div>
<div class="card mb-3">
    <div class="card-body">
        <form
            method="GET"
            id="salesFilterForm"
            class="row g-2"
        >
  <div class="col-md-3"><input name="q" value="{{ request('q') }}" class="form-control" placeholder="Invoice…"></div>
  <div class="col-md-2"><input name="from" type="date" value="{{ request('from') }}" class="form-control"></div>
  <div class="col-md-2"><input name="to" type="date" value="{{ request('to') }}" class="form-control"></div>
            <div class="col-md-12">
                <div
                    class="d-flex flex-wrap gap-1"
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
                </div>
            </div>
  <div class="col-md-2"><select name="status" class="form-select"><option value="">Semua status</option>
    @foreach(['lunas','dp','cicilan','belum'] as $s)<option value="{{ $s }}" {{ request('status')===$s?'selected':'' }}>{{ ucfirst($s) }}</option>@endforeach</select></div>
  <div class="col-md-2">
    <select name="method" class="form-select">
      <option value="">Semua metode</option>
      @foreach(['tunai','transfer','qris','cicilan','dp'] as $m)
        <option value="{{ $m }}" {{ request('method')===$m?'selected':'' }}>
          {{ ucfirst($m) }}
        </option>
      @endforeach
    </select>
  </div>
  <div class="col-md-3">
    <select name="customer_id" class="form-select">
      <option value="">Semua pelanggan</option>
      <option value="umum" {{ request('customer_id')==='umum'?'selected':'' }}>
        Umum (tanpa pelanggan)
      </option>
      @foreach($customers as $c)
        <option
          value="{{ $c->id }}"
          {{ (string) request('customer_id')===(string) $c->id?'selected':'' }}
        >
          {{ $c->name }}{{ $c->phone ? ' — '.$c->phone : '' }}
        </option>
      @endforeach
    </select>
  </div>
  <div class="col-md-3"><button class="btn btn-secondary">Filter</button>
    <div class="d-flex flex-wrap gap-1 mt-1">
      <a href="{{ route('sales.index', array_merge(request()->except('export'), ['export' => 'csv'])) }}" class="btn btn-outline-success btn-sm"><i class="ti ti-download me-1"></i> Export CSV</a>
      <a href="{{ route('sales.index', array_merge(request()->except('export'), ['export' => 'items'])) }}" class="btn btn-outline-info btn-sm"><i class="ti ti-download me-1"></i> Export Item CSV</a>
    </div>
  @if(($user_role = auth()->user()->role ?? '')==='superadmin')<label class="form-check form-check-inline ms-2"><input type="checkbox" name="all" value="1" class="form-check-input" {{ request()->boolean('all')?'checked':'' }}> semua toko</label>@endif</div>
</form></div></div>
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
<div class="card"><div class="card-body border-bottom d-flex flex-wrap align-items-center gap-2">
  <button type="button" class="btn btn-sm btn-outline-primary" id="printSelectedSalesBtn"><i class="ti ti-printer me-1"></i> Cetak Terpilih (maks 50)</button>
  <button type="button" class="btn btn-sm btn-outline-warning" id="printAgreementsBtn"><i class="ti ti-file-text me-1"></i> Cetak Perjanjian (maks 20, cicilan/DP)</button>
  <span class="form-hint">Centang baris transaksi — invoice digabung massal; perjanjian hanya cicilan/DP, non-cicilan dilewati.</span>
</div><div class="table-responsive"><table class="table table-hover mb-0">
<thead><tr><th class="w-1"><input type="checkbox" class="form-check-input" id="checkAllSales" title="Pilih semua"></th><th>Invoice</th><th>Tanggal</th><th>Toko</th><th>Pelanggan</th><th class="text-end">Total</th><th class="text-end">Profit</th><th>Status</th><th></th></tr></thead>
<tbody>@forelse($sales as $s)<tr>
  <td><input type="checkbox" class="form-check-input sale-check" value="{{ $s->id }}"></td>
  <td class="font-monospace">{{ $s->invoice_no }}</td><td class="small">{{ $s->created_at->format('d/m/Y H:i') }}</td>
  <td>{{ $s->store->name ?? '' }}</td><td>{{ $s->customer->name ?? 'Umum' }}</td>
  <td class="text-end">Rp {{ number_format($s->total,0,',','.') }}</td>
  <td class="text-end text-success">Rp {{ number_format($s->profit_total ?? 0,0,',','.') }}</td>
  <td><span class="badge bg-{{ $s->payment_status==='lunas'?'green':($s->payment_status==='belum'?'red':'yellow') }}-lt">{{ strtoupper($s->payment_status) }}</span><div class="small text-secondary">{{ $s->payment_method }}</div></td>
  <td class="d-flex gap-1"><a href="{{ route('sales.show',$s) }}" class="btn btn-sm btn-outline-primary">Detail</a>
    @if(str_contains((string) $s->note, '[VOID]'))
      <span class="badge bg-red-lt align-self-center">VOID</span>
    @elseif($s->created_at->isToday() && (int) $s->user_id === (int) auth()->id())
      <form method="POST" action="{{ route('returns.store') }}" onsubmit="return confirm('Void {{ $s->invoice_no }}? Stok/unit dikembalikan & kas penyesuai keluar Rp {{ number_format($s->total,0,',','.') }} dicatat. Lanjut?')">
        @csrf
        <input type="hidden" name="sale_id" value="{{ $s->id }}">
        <input type="hidden" name="amount" value="{{ $s->total }}">
        <input type="hidden" name="type" value="retur">
        <input type="hidden" name="reason" value="[VOID] koreksi kasir {{ $s->invoice_no }}">
        <button class="btn btn-sm btn-outline-danger">Void</button>
      </form>
    @endif
  </td>
</tr>@empty<tr><td colspan="8"><div class="empty py-3"><div class="empty-icon"><i class="ti ti-receipt-off fs-1 text-secondary"></i></div><p class="empty-title">Belum ada transaksi</p><p class="empty-subtitle text-secondary">Transaksi dari POS akan tercatat di sini. Gunakan filter di atas atau buat transaksi baru.</p><div class="empty-action"><a href="{{ route('pos.index') }}" class="btn btn-primary"><i class="ti ti-plus me-1"></i>Buat Transaksi</a></div></div></td></tr>@endforelse</tbody></table></div>
<div class="card-footer">{{ $sales->links() }}</div></div>
<script>
document.getElementById('checkAllSales')?.addEventListener('change', function () {
    document.querySelectorAll('.sale-check').forEach(function (cb) {
        cb.checked = document.getElementById('checkAllSales').checked;
    });
});
document.getElementById('printSelectedSalesBtn')?.addEventListener('click', function () {
    const ids = Array.from(document.querySelectorAll('.sale-check:checked')).map(function (cb) {
        return cb.value;
    });
    if (!ids.length) {
        alert('Pilih minimal 1 transaksi dulu (centang baris tabel).');
        return;
    }
    if (ids.length > 50) {
        alert('Maksimal 50 invoice per cetak massal.');
        return;
    }
    const q = ids.map(function (id) {
        return 'ids[]=' + encodeURIComponent(id);
    }).join('&');
    window.open("{{ route('sales.bulkInvoices') }}?" + q, '_blank');
});
document.getElementById('printAgreementsBtn')?.addEventListener('click', function () {
    const ids = Array.from(document.querySelectorAll('.sale-check:checked')).map(function (cb) {
        return cb.value;
    });
    if (!ids.length) {
        alert('Pilih minimal 1 transaksi dulu (centang baris tabel).');
        return;
    }
    if (ids.length > 20) {
        alert('Maksimal 20 perjanjian per cetak massal (khusus cicilan/DP).');
        return;
    }
    const q = ids.map(function (id) {
        return 'ids[]=' + encodeURIComponent(id);
    }).join('&');
    window.open("{{ route('sales.bulkAgreements') }}?" + q, '_blank');
});
(function () {
    var form = document.getElementById('salesFilterForm');
    if (!form) {
        return;
    }
    function fmt(d) {
        return d.toISOString().slice(0, 10);
    }
    function applyRange(from, to) {
        var fromEl = form.querySelector('input[name="from"]');
        var toEl = form.querySelector('input[name="to"]');
        if (fromEl) {
            fromEl.value = from;
        }
        if (toEl) {
            toEl.value = to;
        }
        form.submit();
    }
    form.querySelectorAll('[data-preset]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var now = new Date();
            var mode = btn.getAttribute('data-preset');
            if (mode === 'today') {
                var t = fmt(now);
                applyRange(t, t);
            } else if (mode === 'week') {
                var start = new Date(now);
                start.setDate(start.getDate() - 6);
                applyRange(fmt(start), fmt(now));
            } else {
                var first = new Date(now.getFullYear(), now.getMonth(), 1);
                applyRange(fmt(first), fmt(now));
            }
        });
    });
})();
</script>
@endsection
