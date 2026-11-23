@extends('layouts.tabler')
@section('title','Kesehatan Data')
@section('content')
<div class="page-header d-print-none no-print">
  <div class="row align-items-center">
    <div class="col">
      <div class="page-pretitle">Owner • Semua toko • Read-only + aksi aman</div>
      <h2 class="page-title"><i class="ti ti-heartbeat me-1"></i> Kesehatan Data</h2>
      <div class="text-secondary">Deteksi inkonsistensi data. Tombol perbaikan hanya tersedia untuk 4 kasus yang 100% aman; sisanya untuk tindak lanjut manual.</div>
    </div>
    <div class="col-auto ms-auto">
      <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary">← Dashboard</a>
    </div>
    <div class="col-auto ms-auto">
      @php
        $safeTotal = $unitsSold->count()
          + $badCommissions->count()
          + $orphanServiceUnits->count()
          + $expiredWarranties->count();
      @endphp
      <form
        method="POST"
        action="{{ route('health.fix_all_safe') }}"
        data-loading
        class="d-inline"
      >
        @csrf
        <button
          type="submit"
          class="btn btn-success"
          @if($safeTotal === 0) disabled title="Tidak ada anomali aman" @endif
          onclick="return confirm('Jalankan SEMUA perbaikan aman berurutan?\n1) unit terjual → tandai terjual\n2) unit yatim → kembalikan tersedia\n3) komisi batal → hapus\n4) garansi kedaluwarsa → tutup.\nYang gagal dilewati & dilaporkan.')"
        >
          <i class="ti ti-settings me-1"></i> Perbaiki Semua yang Aman
          <span class="badge bg-white text-success ms-1">{{ $safeTotal }}</span>
        </button>
      </form>
      <div class="text-secondary small mt-1">
        1-klik: unit terjual, unit yatim, komisi batal, garansi kedaluwarsa.
        Stok/sale/kas/hutang tetap manual.
      </div>
    </div>
  </div>
</div>

@if(!empty($checkErrors))
<div class="alert alert-warning mt-3"><ul class="mb-0">@foreach($checkErrors as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

<div class="row row-cards mt-2">
  <div class="col-sm-6 col-lg-2"><div class="card"><div class="card-body"><div class="subheader">Unit salah status</div><div class="h1">{{ $unitsSold->count() }}</div></div></div></div>
  <div class="col-sm-6 col-lg-2"><div class="card"><div class="card-body"><div class="subheader">Stok negatif</div><div class="h1">{{ $negStocks->count() }}</div></div></div></div>
  <div class="col-sm-6 col-lg-2"><div class="card"><div class="card-body"><div class="subheader">Selisih sale</div><div class="h1">{{ $saleGaps->count() }}</div></div></div></div>
  <div class="col-sm-6 col-lg-2"><div class="card"><div class="card-body"><div class="subheader">Selisih kas</div><div class="h1">{{ $cashGaps->count() }}</div></div></div></div>
  <div class="col-sm-6 col-lg-2"><div class="card"><div class="card-body"><div class="subheader">Selisih hutang</div><div class="h1">{{ $debtGaps->count() }}</div></div></div></div>
  <div class="col-sm-6 col-lg-2"><div class="card"><div class="card-body"><div class="subheader">Komisi batal</div><div class="h1">{{ $badCommissions->count() }}</div></div></div></div>
  <div class="col-sm-6 col-lg-2"><div class="card"><div class="card-body"><div class="subheader">Unit service yatim</div><div class="h1">{{ $orphanServiceUnits->count() }}</div></div></div></div>
  <div class="col-sm-6 col-lg-2"><div class="card"><div class="card-body"><div class="subheader">Garansi kedaluwarsa</div><div class="h1">{{ $expiredWarranties->count() }}</div></div></div></div>
  <div class="col-12 col-lg-4">
    <div class="card">
      <div class="card-body">
        <div class="subheader">Kapasitas Penyimpanan</div>
        @if(is_null($disk['pct'] ?? null))
          <div class="h1 mb-1">-</div>
          <div class="text-secondary small">Info disk tidak terbaca.</div>
        @else
          <div class="d-flex align-items-center gap-2 mb-1">
            <div class="h1 mb-0">{{ $disk['pct'] }}%</div>
            <span class="badge {{ $disk['badge'] }}">{{ $disk['label'] }}</span>
          </div>
          <div class="progress mb-2" style="height: 8px;">
            <div
              class="progress-bar {{ ($disk['pct'] ?? 0) > 90 ? 'bg-danger' : (($disk['pct'] ?? 0) >= 70 ? 'bg-warning' : 'bg-success') }}"
              role="progressbar"
              style="width: {{ $disk['pct'] }}%;"
              aria-valuenow="{{ $disk['pct'] }}"
              aria-valuemin="0"
              aria-valuemax="100"
            ></div>
          </div>
          <div class="text-secondary small">
            Terpakai {{ number_format(($disk['used'] ?? 0) / 1073741824, 2) }} GB
            dari {{ number_format(($disk['total'] ?? 0) / 1073741824, 2) }} GB
            • Bebas {{ number_format(($disk['free'] ?? 0) / 1073741824, 2) }} GB
            <div class="font-monospace">{{ storage_path() }}</div>
          </div>
          @if(($disk['pct'] ?? 0) > 90)
            <div class="alert alert-danger mt-2 mb-0 small">
              Penyimpanan hampir penuh. Bersihkan backup lama di
              <code>storage/app/backups</code> atau hubungi admin bila penuh.
            </div>
          @elseif(($disk['pct'] ?? 0) >= 70)
            <div class="alert alert-warning mt-2 mb-0 small">
              Penggunaan &ge; 70%. Pertimbangkan bersihkan backup lama secara berkala.
            </div>
          @endif
        @endif
      </div>
    </div>
  </div>
</div>

{{-- 1. Unit tersedia tapi terjual — ADA tombol aman --}}
<div class="card mt-3">
  <div class="card-header"><h3 class="card-title">1. Unit “tersedia” tapi sudah terjual <span class="badge bg-red-lt ms-1">{{ $unitsSold->count() }}</span></h3></div>
  <div class="table-responsive"><table class="table table-sm card-table mb-0">
    <thead><tr><th>IMEI / Serial</th><th>Toko</th><th>Invoice</th><th class="text-end">Aksi aman</th></tr></thead>
    <tbody>
    @forelse($unitsSold as $u)
    <tr>
      <td class="font-monospace">{{ $u->imei ?: '-' }}<div class="small text-secondary">{{ $u->serial ?: '' }}</div></td>
      <td>{{ $u->store_name ?? ('#'.$u->store_id) }}</td>
      <td><a href="{{ route('sales.show', $u->sale_id) }}">{{ $u->invoice_no }}</a></td>
      <td class="text-end">
        <form method="POST" action="{{ route('health.unit.sold', $u->id) }}" data-loading class="d-inline">
          @csrf
          <button class="btn btn-sm btn-warning" onclick="return confirm('Tandai unit {{ $u->imei }} sebagai TERJUAL? (hanya update status)')">Tandai Terjual</button>
        </form>
      </td>
    </tr>
    @empty<tr><td colspan="4" class="text-center text-secondary"><i class="ti ti-check me-1"></i> Tidak ada anomali.</td></tr>@endforelse
    </tbody>
  </table></div>
  <div class="card-footer text-secondary small">Hanya update kolom <code>status → terjual</code>. Unit yang penjualannya memiliki retur otomatis dikecualikan. Maksimal 100 baris.</div>
</div>

{{-- 2. Stok negatif — tanpa tombol --}}
<div class="card mt-3">
  <div class="card-header"><h3 class="card-title">2. Stok negatif (qty &lt; 0) <span class="badge bg-red-lt ms-1">{{ $negStocks->count() }}</span></h3></div>
  <div class="table-responsive"><table class="table table-sm card-table mb-0">
    <thead><tr><th>Produk</th><th>Toko</th><th class="text-end">Qty</th></tr></thead>
    <tbody>
    @forelse($negStocks as $s)<tr><td>{{ $s->product_name }}</td><td>{{ $s->store_name ?? '-' }}</td><td class="text-end"><span class="badge bg-red-lt">{{ $s->qty }}</span></td></tr>
    @empty<tr><td colspan="3" class="text-center text-secondary"><i class="ti ti-check me-1"></i> Tidak ada stok negatif.</td></tr>@endforelse
    </tbody>
  </table></div>
  <div class="card-footer text-secondary small">Tanpa tombol: perbaiki via <a href="{{ route('stocks.opname') }}">Stok Opname</a> setelah cek fisik.</div>
</div>

{{-- 3. Selisih sale — tanpa tombol --}}
<div class="card mt-3">
  <div class="card-header"><h3 class="card-title">3. Sale: remaining ≠ total − paid <span class="badge bg-yellow-lt ms-1">{{ $saleGaps->count() }}</span></h3></div>
  <div class="table-responsive"><table class="table table-sm card-table mb-0">
    <thead><tr><th>Invoice</th><th class="text-end">Total</th><th class="text-end">Paid (kolom)</th><th class="text-end">Σ Pembayaran</th><th class="text-end">Remaining</th><th class="text-end">Seharusnya</th></tr></thead>
    <tbody>
    @forelse($saleGaps as $s)
    <tr>
      <td><a href="{{ route('sales.show', $s->id) }}" class="font-monospace">{{ $s->invoice_no }}</a></td>
      <td class="text-end">{{ number_format($s->total,0,',','.') }}</td>
      <td class="text-end">{{ number_format($s->paid_amount,0,',','.') }}</td>
      <td class="text-end">{{ is_null($s->paid_sum) ? '-' : number_format($s->paid_sum,0,',','.') }}</td>
      <td class="text-end">{{ number_format($s->remaining,0,',','.') }}</td>
      <td class="text-end"><span class="badge bg-yellow-lt">{{ number_format($s->expected,0,',','.') }}</span></td>
    </tr>
    @empty<tr><td colspan="6" class="text-center text-secondary"><i class="ti ti-check me-1"></i> Semua sale konsisten (toleransi Rp 1).</td></tr>@endforelse
    </tbody>
  </table></div>
  <div class="card-footer text-secondary small">Tanpa tombol “Selaraskan”: nilai <code>paid_amount</code> sendiri bisa salah dibanding tabel pembayaran — bandingkan kolom Σ Pembayaran lalu koreksi manual di detail transaksi. Dipindai 2000 sale terbaru, maks 100 baris.</div>
</div>

{{-- 4. Selisih kas — tanpa tombol --}}
<div class="card mt-3">
  <div class="card-header"><h3 class="card-title">4. Kas: balance ≠ Σ mutasi <span class="badge bg-yellow-lt ms-1">{{ $cashGaps->count() }}</span></h3></div>
  <div class="table-responsive"><table class="table table-sm card-table mb-0">
    <thead><tr><th>Akun</th><th>Toko</th><th class="text-end">Balance</th><th class="text-end">Masuk</th><th class="text-end">Keluar</th><th class="text-end">Neto mutasi</th><th class="text-end">Selisih</th></tr></thead>
    <tbody>
    @forelse($cashGaps as $a)
    <tr>
      <td>{{ $a->name }}</td><td>{{ $a->store_name ?? '-' }}</td>
      <td class="text-end">{{ number_format($a->balance,0,',','.') }}</td>
      <td class="text-end text-success">{{ number_format($a->mutasi_masuk,0,',','.') }}</td>
      <td class="text-end text-danger">{{ number_format($a->mutasi_keluar,0,',','.') }}</td>
      <td class="text-end">{{ number_format($a->mutasi_neto,0,',','.') }}</td>
      <td class="text-end"><span class="badge bg-yellow-lt">{{ number_format($a->diff,0,',','.') }}</span></td>
    </tr>
    @empty<tr><td colspan="7" class="text-center text-secondary"><i class="ti ti-check me-1"></i> Semua akun kas konsisten (toleransi Rp 1).</td></tr>@endforelse
    </tbody>
  </table></div>
  <div class="card-footer text-secondary small">Tanpa tombol “Hitung Ulang”: tabel <code>cash_accounts</code> tidak menyimpan saldo awal, jadi selisih dapat berasal dari opening yang tak terlacak — audit manual via <a href="{{ route('cash.index') }}">Kas</a>.</div>
</div>

{{-- 5. Selisih hutang — tanpa tombol --}}
<div class="card mt-3">
  <div class="card-header"><h3 class="card-title">5. Hutang/Piutang: remaining ≠ total − paid <span class="badge bg-yellow-lt ms-1">{{ $debtGaps->count() }}</span></h3></div>
  <div class="table-responsive"><table class="table table-sm card-table mb-0">
    <thead><tr><th>Pihak</th><th>Tipe</th><th>Status</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Remaining</th><th class="text-end">Seharusnya</th></tr></thead>
    <tbody>
    @forelse($debtGaps as $d)
    <tr>
      <td><a href="{{ route('debts.index') }}">{{ $d->party_name }}</a><div class="small text-secondary">#{{ $d->id }}</div></td>
      <td><span class="badge bg-blue-lt">{{ $d->type }}</span></td><td>{{ $d->status }}</td>
      <td class="text-end">{{ number_format($d->total,0,',','.') }}</td>
      <td class="text-end">{{ number_format($d->paid,0,',','.') }}</td>
      <td class="text-end">{{ number_format($d->remaining,0,',','.') }}</td>
      <td class="text-end"><span class="badge bg-yellow-lt">{{ number_format($d->expected,0,',','.') }}</span></td>
    </tr>
    @empty<tr><td colspan="7" class="text-center text-secondary"><i class="ti ti-check me-1"></i> Semua hutang/piutang konsisten (toleransi Rp 1).</td></tr>@endforelse
    </tbody>
  </table></div>
  <div class="card-footer text-secondary small">Tanpa tombol: koreksi manual di <a href="{{ route('debts.index') }}">Hutang &amp; Piutang</a>. Dipindai 2000 record terbaru, maks 100 baris.</div>
</div>

{{-- 6. Komisi tiket batal — ADA tombol aman --}}
<div class="card mt-3">
  <div class="card-header"><h3 class="card-title">6. Komisi teknisi untuk tiket batal <span class="badge bg-red-lt ms-1">{{ $badCommissions->count() }}</span></h3></div>
  <div class="table-responsive"><table class="table table-sm card-table mb-0">
    <thead><tr><th>Tiket</th><th>Teknisi</th><th class="text-end">Nominal</th><th class="text-end">Aksi aman</th></tr></thead>
    <tbody>
    @forelse($badCommissions as $c)
    <tr>
      <td class="font-monospace">{{ $c->ticket_no }}</td>
      <td>{{ $c->technician_name ?? '-' }}</td>
      <td class="text-end">Rp {{ number_format($c->amount,0,',','.') }}</td>
      <td class="text-end">
        <form method="POST" action="{{ route('health.commission.delete', $c->id) }}" data-loading class="d-inline">
          @csrf
          <button class="btn btn-sm btn-danger" onclick="return confirm('Hapus komisi menunggu Rp {{ number_format($c->amount,0,',','.') }} untuk tiket batal {{ $c->ticket_no }}?')">Hapus Komisi</button>
        </form>
      </td>
    </tr>
    @empty<tr><td colspan="4" class="text-center text-secondary"><i class="ti ti-check me-1"></i> Tidak ada komisi menggantung di tiket batal.</td></tr>@endforelse
    </tbody>
  </table></div>
  <div class="card-footer text-secondary small">Hanya komisi <code>menunggu</code> yang ditampilkan &amp; dapat dihapus. Komisi <code>dibayar</code> tidak disentuh (butuh koreksi kas manual). Maksimal 100 baris.</div>
</div>

{{-- 7. Unit service yatim — ADA tombol aman --}}
<div class="card mt-3">
  <div class="card-header"><h3 class="card-title">7. Unit “service” tanpa tiket aktif <span class="badge bg-red-lt ms-1">{{ $orphanServiceUnits->count() }}</span></h3></div>
  <div class="table-responsive"><table class="table table-sm card-table mb-0">
    <thead><tr><th>IMEI / Serial</th><th>Produk</th><th>Toko</th><th class="text-end">Aksi aman</th></tr></thead>
    <tbody>
    @forelse($orphanServiceUnits as $u)
    <tr>
      <td class="font-monospace">{{ $u->imei ?: '-' }}<div class="small text-secondary">{{ $u->serial ?: '' }}</div></td>
      <td>{{ $u->product_name ?? '-' }}</td>
      <td>{{ $u->store_name ?? ('#'.$u->store_id) }}</td>
      <td class="text-end">
        <form method="POST" action="{{ route('health.unit.available', $u->id) }}" data-loading class="d-inline">
          @csrf
          <button class="btn btn-sm btn-success" onclick="return confirm('Kembalikan unit {{ $u->imei }} ke TERSEDIA? (hanya update status)')">Kembalikan ke Tersedia</button>
        </form>
      </td>
    </tr>
    @empty<tr><td colspan="4" class="text-center text-secondary"><i class="ti ti-check me-1"></i> Tidak ada unit service yatim.</td></tr>@endforelse
    </tbody>
  </table></div>
  <div class="card-footer text-secondary small">Hanya update kolom <code>status → tersedia</code>. Hanya unit ber-IMEI yang persis tidak punya tiket aktif (<code>diserahkan/batal</code> dikecualikan dari “aktif”) yang ditampilkan; unit tanpa IMEI dikecualikan. Maksimal 100 baris.</div>
</div>

{{-- 8. Garansi kedaluwarsa — ADA tombol aman --}}
<div class="card mt-3">
  <div class="card-header">
    <h3 class="card-title">8. Garansi “aktif” tapi sudah lewat tanggal berakhir <span class="badge bg-red-lt ms-1">{{ $expiredWarranties->count() }}</span></h3>
  </div>
  <div class="table-responsive">
    <table class="table table-sm card-table mb-0">
      <thead>
        <tr>
          <th>ID</th>
          <th>Pelanggan</th>
          <th>Ref</th>
          <th>Berakhir</th>
          <th class="text-end">Aksi aman</th>
        </tr>
      </thead>
      <tbody>
        @forelse($expiredWarranties as $w)
          <tr>
            <td class="font-monospace">#{{ $w->id }}</td>
            <td>{{ $w->customer_name ?? '-' }}</td>
            <td class="font-monospace">{{ $w->ref_type ?? '-' }} {{ $w->ref_id ?? '' }}</td>
            <td>{{ $w->end_date }}</td>
            <td class="text-end">
              <form
                method="POST"
                action="{{ route('health.warranty.expired', $w->id) }}"
                data-loading
                class="d-inline"
              >
                @csrf
                <button
                  class="btn btn-sm btn-secondary"
                  onclick="return confirm('Tandai garansi #{{ $w->id }} sebagai KEDALUWARSA? (hanya update status → selesai)')"
                >Tandai Kedaluwarsa</button>
              </form>
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="5" class="text-center text-secondary"><i class="ti ti-check me-1"></i> Tidak ada garansi kedaluwarsa berstatus aktif.</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <div class="card-footer text-secondary small">Hanya update kolom <code>status → selesai</code> + penanda <code>[KEDALUWARSA otomatis]</code> di <code>claim_note</code>. Tanpa hapus baris. Maksimal 100 baris.</div>
</div>
@endsection
