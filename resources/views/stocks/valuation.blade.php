@extends('layouts.tabler')
@section('title', 'Nilai Persediaan')
@section('content')
<div class="page-header"><div class="row align-items-center"><div class="col"><div class="page-pretitle">Inventory · {{ $storeName }}</div><h2 class="page-title">Nilai Persediaan</h2><div class="text-secondary">{{ $total['count'] }} produk bervolume (qty &gt; 0) di toko aktif. Modal tertanam <b>Rp {{ number_format($total['modal'], 0, ',', '.') }}</b> · nilai jual <b>Rp {{ number_format($total['jual'], 0, ',', '.') }}</b> · potensi profit <b>Rp {{ number_format($total['profit'], 0, ',', '.') }}</b>.</div></div>
<div class="col-auto d-flex gap-2 d-print-none">
<a href="{{ route('stocks.opname') }}" class="btn btn-outline-secondary">Opname</a>
<a href="{{ route('stocks.deadstock') }}" class="btn btn-outline-danger">Stok Mati</a>
<button onclick="window.print()" class="btn btn-outline-primary"><i class="ti ti-printer me-1"></i> Cetak</button>
<a href="{{ route('stocks.valuation', array_merge(request()->query(), ['export' => 'csv'])) }}" class="btn btn-success"><i class="ti ti-download me-1"></i> Export CSV</a>
</div></div></div>
<div class="row g-3 mb-3">
<div class="col-md-3"><div class="card"><div class="card-body"><div class="text-secondary small">Total Qty</div><div class="h2 mb-0">{{ number_format($total['qty'], 0, ',', '.') }}</div></div></div></div>
<div class="col-md-3"><div class="card"><div class="card-body"><div class="text-secondary small">Nilai Modal (qty × beli)</div><div class="h2 mb-0">Rp {{ number_format($total['modal'], 0, ',', '.') }}</div></div></div></div>
<div class="col-md-3"><div class="card"><div class="card-body"><div class="text-secondary small">Nilai Jual (qty × jual)</div><div class="h2 mb-0">Rp {{ number_format($total['jual'], 0, ',', '.') }}</div></div></div></div>
<div class="col-md-3"><div class="card"><div class="card-body"><div class="text-secondary small">Kas Toko Aktif (konteks)</div><div class="h2 mb-0">Rp {{ number_format($kas, 0, ',', '.') }}</div><div class="small text-secondary">Modal ≈ {{ $kas > 0 ? number_format($total['modal'] / $kas * 100, 1, ',', '.') : '–' }}% dari kas · aset lancar ≈ Rp {{ number_format($total['modal'] + $kas, 0, ',', '.') }}</div></div></div></div>
</div>
<div class="card mb-3 d-print-none"><div class="card-body"><form method="GET" class="row g-2 align-items-end">
<div class="col-md-3"><label class="form-label">Tipe</label><select name="type" class="form-select"><option value="">Semua tipe</option>@foreach(['hp_baru','hp_bekas','refurbished','aksesoris','sparepart','pulsa','paket_data','lain'] as $t)<option value="{{ $t }}" {{ request('type')===$t?'selected':'' }}>{{ $t }}</option>@endforeach</select></div>
<div class="col-md-7"><label class="form-label">Cari</label><input name="q" value="{{ request('q') }}" class="form-control" placeholder="Nama / SKU…"></div>
<div class="col-md-2"><button class="btn btn-secondary w-100">Filter</button></div></form></div></div>
<div class="row g-3 mb-3">
<div class="col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Rekap per Tipe</h3></div>
<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Tipe</th><th class="text-end">Qty</th><th class="text-end">Modal</th><th class="text-end">Jual</th><th class="text-end">Profit</th></tr></thead>
<tbody>@forelse($byType as $g)<tr><td>{{ $g['label'] }}<div class="small text-secondary">{{ $g['count'] }} produk</div></td><td class="text-end">{{ number_format($g['qty'], 0, ',', '.') }}</td><td class="text-end">{{ number_format($g['modal'], 0, ',', '.') }}</td><td class="text-end">{{ number_format($g['jual'], 0, ',', '.') }}</td><td class="text-end">{{ number_format($g['profit'], 0, ',', '.') }}</td></tr>@empty<tr><td colspan="5" class="text-center text-secondary">—</td></tr>@endforelse</tbody></table></div></div></div>
<div class="col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Rekap per Kategori</h3></div>
<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Kategori</th><th class="text-end">Qty</th><th class="text-end">Modal</th><th class="text-end">Jual</th><th class="text-end">Profit</th></tr></thead>
<tbody>@forelse($byCategory as $g)<tr><td>{{ $g['label'] }}<div class="small text-secondary">{{ $g['count'] }} produk</div></td><td class="text-end">{{ number_format($g['qty'], 0, ',', '.') }}</td><td class="text-end">{{ number_format($g['modal'], 0, ',', '.') }}</td><td class="text-end">{{ number_format($g['jual'], 0, ',', '.') }}</td><td class="text-end">{{ number_format($g['profit'], 0, ',', '.') }}</td></tr>@empty<tr><td colspan="5" class="text-center text-secondary">—</td></tr>@endforelse</tbody></table></div></div></div>
</div>
<div class="card"><div class="card-header"><h3 class="card-title">Rincian Produk ({{ count($rows) }})</h3></div>
<div class="table-responsive"><table class="table table-hover mb-0">
<thead><tr><th>SKU</th><th>Produk</th><th>Kategori</th><th>Tipe</th><th class="text-end">Qty</th><th class="text-end">Beli @</th><th class="text-end">Jual @</th><th class="text-end">Nilai Modal</th><th class="text-end">Nilai Jual</th><th class="text-end">Potensi Profit</th></tr></thead>
<tbody>@forelse($rows as $r)<tr>
<td class="font-monospace small">{{ $r['sku'] }}</td>
<td>{{ $r['name'] }}<div class="small text-secondary">{{ $r['brand'] }}</div></td>
<td class="small">{{ $r['category'] }}</td><td class="small">{{ $r['type'] }}</td>
<td class="text-end"><b>{{ $r['qty'] }}</b></td>
<td class="text-end">{{ number_format($r['purchase_price'], 0, ',', '.') }}</td>
<td class="text-end">{{ number_format($r['selling_price'], 0, ',', '.') }}</td>
<td class="text-end">{{ number_format($r['nilai_modal'], 0, ',', '.') }}</td>
<td class="text-end">{{ number_format($r['nilai_jual'], 0, ',', '.') }}</td>
<td class="text-end">{{ number_format($r['profit'], 0, ',', '.') }}</td>
</tr>@empty<tr><td colspan="10"><div class="empty py-3"><p class="empty-title">Tidak ada stok bervolume</p><p class="empty-subtitle text-secondary">Tidak ada produk dengan qty &gt; 0 di toko aktif untuk filter ini.</p></div></td></tr>@endforelse</tbody>
<tfoot><tr class="fw-bold"><td colspan="4" class="text-end">TOTAL</td><td class="text-end">{{ number_format($total['qty'], 0, ',', '.') }}</td><td></td><td></td><td class="text-end">{{ number_format($total['modal'], 0, ',', '.') }}</td><td class="text-end">{{ number_format($total['jual'], 0, ',', '.') }}</td><td class="text-end">{{ number_format($total['profit'], 0, ',', '.') }}</td></tr></tfoot></table></div>
<div class="card-footer text-secondary small">Rumus: nilai modal = qty × harga beli, nilai jual = qty × harga jual, potensi profit = nilai jual − nilai modal. Kas hanya konteks (saldo akun kas toko aktif), bukan pengurang valuasi.</div></div>
@endsection
