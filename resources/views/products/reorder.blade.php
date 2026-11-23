@extends('layouts.tabler')
@section('title', 'Saran Belanja Otomatis')
@section('content')
<div class="page-header"><div class="row align-items-center"><div class="col">
  <div class="page-pretitle">Inventory - Toko: {{ $storeName }}</div>
  <h2 class="page-title">Saran Belanja Otomatis</h2>
  <div class="text-secondary">Produk aktif dengan stok &le; minimum - saran qty = max(min&times;2 &minus; stok, 1) - saran min = ceil(terjual 30 hari / 30 x 7 x 1,2), min 1 - tahan = stok &divide; (terjual 30 hari / 30), &infin; bila tanpa penjualan - {{ count($rows) }} item - estimasi total Rp {{ number_format($totalEstimate, 0, ',', '.') }}</div>
</div>
<div class="col-auto d-flex gap-2">
  <a href="{{ route('products.index') }}" class="btn btn-outline-secondary">Produk</a>
  <button class="btn btn-outline-primary" onclick="window.print()">Cetak</button>
</div></div></div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
@endif

@if(!count($rows))
<div class="card"><div class="card-body"><div class="empty py-4">
  <p class="empty-title">Semua stok aman</p>
  <p class="empty-subtitle text-secondary">Tidak ada produk aktif yang stoknya di bawah minimum di toko ini.</p>
</div></div></div>
@else
<div class="card mb-3"><div class="table-responsive"><table class="table table-vcenter card-table">
<thead>
<tr>
<th>Produk</th>
<th class="text-end">Stok kini</th>
<th class="text-end">Tahan (hari)</th>
<th class="text-end">Habis tgl</th>
<th class="text-end">Minimum</th>
<th class="text-end">Saran Min</th>
<th class="text-end">Kurang (saran)</th>
<th class="text-end">Harga beli terakhir</th>
<th class="text-end">Estimasi nilai</th>
<th>Supplier terakhir</th>
<th>Termurah (12 bln)</th>
</tr>
</thead>
<tbody>
@foreach($rows as $r)
<tr>
  <td>{{ $r['name'] }}<div class="small text-secondary">{{ $r['sku'] }} - {{ $r['brand'] }} @if($r['is_serialized'])<span class="badge bg-blue-lt">IMEI</span>@endif</div>
    <div class="small text-secondary">Terjual 30 hari: {{ (int) $r['sold30'] }}</div></td>
  <td class="text-end"><b class="text-danger">{{ $r['stock'] }}</b></td>
  <td class="text-end">
    @if(is_null($r['days_cover']))
      <b title="Tanpa penjualan 30 hari — kecepatan harian 0">&infin;</b>
      <div class="small text-secondary">tanpa jual 30 hr</div>
    @elseif($r['days_cover'] < 7)
      <b class="text-danger" title="Stok {{ $r['stock'] }} / {{ number_format($r['velocity'], 2, ',', '.') }}/hari &lt; lead time 7 hari">{{ number_format($r['days_cover'], 1, ',', '.') }} hr</b>
      <div>
        <span class="badge bg-red-lt" title="Ketahanan di bawah lead time 7 hari — segera restock">&lt; 7 hr</span>
      </div>
    @else
      <b title="Stok {{ $r['stock'] }} / {{ number_format($r['velocity'], 2, ',', '.') }}/hari">{{ number_format($r['days_cover'], 1, ',', '.') }} hr</b>
      <div class="small text-secondary">{{ number_format($r['velocity'], 2, ',', '.') }}/hari</div>
    @endif
  </td>
  <td class="text-end">
    @if(is_null($r['days_cover']))
      <b title="Velocity 0 — tanpa penjualan 30 hari">&infin;</b>
      <div class="small text-secondary">
        tanpa jual
      </div>
    @else
      @php
        $habisTgl = \Carbon\Carbon::today()->addDays((int) floor($r['days_cover']));
      @endphp
      <b title="Hari ini + {{ number_format($r['days_cover'], 1, ',', '.') }} hari = {{ $habisTgl->format('d/m/Y') }}">
        {{ $habisTgl->format('d/m') }}
      </b>
      <div class="small text-secondary">
        {{ (int) floor($r['days_cover']) }} hr lagi
      </div>
    @endif
  </td>
  <td class="text-end">{{ $r['min'] }}@if(!is_null($r['store_min'] ?? null))<div class="small text-secondary" title="Min toko {{ $r['store_min'] }} (global {{ $r['global_min'] ?? 0 }})">toko</div>@endif</td>
  <td class="text-end">
    <b>{{ $r['suggest_min'] }}</b>
    @if($r['raise_min'])
      <div><span class="badge bg-yellow-lt">Naikkan Min ke {{ $r['suggest_min'] }}</span></div>
      <form method="POST" action="{{ route('products.min-stock', $r['product_id']) }}" class="mt-1">
        @csrf
        <input type="hidden" name="min_stock" value="{{ $r['suggest_min'] }}">
        <button class="btn btn-sm btn-warning" title="Update min_stock produk ini ke {{ $r['suggest_min'] }} (1-klik)">Update Min</button>
      </form>
    @endif
  </td>
  <td class="text-end"><b>{{ $r['need'] }}</b></td>
  <td class="text-end">{{ number_format($r['price'], 0, ',', '.') }}</td>
  <td class="text-end">{{ number_format($r['estimate'], 0, ',', '.') }}</td>
  <td class="small">{{ $r['supplier'] }}@if($r['invoice'])<div class="text-secondary">{{ $r['invoice'] }}</div>@endif</td>
  <td class="small">
    @if(!empty($r['cheapest_supplier']))
      Termurah: {{ $r['cheapest_supplier'] }} Rp {{ number_format($r['cheapest_price'], 0, ',', '.') }} ({{ \Carbon\Carbon::parse($r['cheapest_date'])->format('d/m/Y') }})
      <div><a href="{{ route('purchases.index', ['supplier_id' => $r['cheapest_supplier_id'], 'product_id' => [$r['product_id']], 'qty' => [$r['need']], 'price' => [$r['cheapest_price']]]) }}" class="btn btn-sm btn-outline-primary mt-1" title="Buat PO ke supplier termurah dengan qty saran & harga termurah">Buat PO</a></div>
    @else
      <span class="text-secondary">-</span>
    @endif
  </td>
</tr>
@endforeach
</tbody>
<tfoot>
<tr>
<th colspan="8" class="text-end">Total estimasi</th>
<th class="text-end">Rp {{ number_format($totalEstimate, 0, ',', '.') }}</th>
<th></th>
<th></th>
</tr>
</tfoot>
</table></div></div>

@foreach($waGroups as $g)
<div class="card mb-3">
    <div class="card-header">
        <h3 class="card-title">
            Supplier: {{ $g['name'] }}
            <span class="text-secondary small">
                ({{ $g['count'] }} jenis, {{ $g['total_qty'] }} pcs — Rp {{ number_format($g['subtotal'], 0, ',', '.') }})
            </span>
        </h3>
        <div class="ms-auto d-flex gap-2 flex-wrap">
            <button
                class="btn btn-sm btn-outline-secondary"
                onclick='salinDaftar(@json($g["name"]), @json($g["items"]->values()->all()))'
            >
                Salin daftar
            </button>
            @if($g['phone_valid'])
                <a
                    href="{{ $g['wa_link'] }}"
                    target="_blank"
                    rel="noopener"
                    class="btn btn-sm btn-outline-success"
                    title="Buka WhatsApp ke {{ $g['phone'] }} dengan daftar restock sudah terisi"
                >
                    Buka WA
                </a>
                <form
                    method="POST"
                    action="{{ route('products.reorder.wa') }}"
                    class="d-inline"
                    onsubmit="return confirm('Kirim daftar restock ({{ $g['count'] }} jenis) via WA ke {{ $g['name'] }} ({{ $g['phone'] }})?')"
                >
                    @csrf
                    <input type="hidden" name="supplier_id" value="{{ $g['sid'] }}">
                    <button
                        class="btn btn-sm btn-success"
                        title="Kirim daftar restock via gateway WA ke supplier ini"
                    >
                        Kirim via WA
                    </button>
                </form>
            @else
                <span
                    class="badge bg-yellow-lt"
                    title="Lengkapi nomor HP supplier untuk mengaktifkan tombol WA"
                >
                    No. WA supplier kosong
                </span>
            @endif
            <a
                href="{{ $g['buy_url'] }}"
                class="btn btn-sm btn-primary"
                title="Buka halaman pembelian dengan daftar ini terbawa di URL (product_id[], qty[], price[])"
            >
                Buat Pembelian
            </a>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th>Produk</th>
                    <th class="text-end">Qty</th>
                    <th class="text-end">Harga</th>
                    <th class="text-end">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                @foreach($g['items'] as $it)
                    <tr>
                        <td>
                            {{ $it['name'] }}
                            <span class="text-secondary small">{{ $it['sku'] }}</span>
                        </td>
                        <td class="text-end">{{ $it['need'] }}</td>
                        <td class="text-end">{{ number_format($it['price'], 0, ',', '.') }}</td>
                        <td class="text-end">{{ number_format($it['estimate'], 0, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endforeach
@endif

@push('js')<script>
function rp(n){ return 'Rp ' + Number(n||0).toLocaleString('id-ID'); }
function salinDaftar(supplier, items){
  let lines = ['Daftar belanja - Supplier: ' + supplier, ''];
  let total = 0;
  items.forEach(it => { lines.push('- ' + it.name + ' (' + it.sku + ') x ' + it.need + ' @ ' + rp(it.price) + ' = ' + rp(it.estimate)); total += Number(it.estimate||0); });
  lines.push('', 'Total estimasi: ' + rp(total));
  const teks = lines.join('\n');
  (navigator.clipboard ? navigator.clipboard.writeText(teks) : Promise.reject())
    .then(() => alert('Daftar belanja disalin. Tempel ke formulir pembelian.'))
    .catch(() => { prompt('Salin manual daftar belanja:', teks); });
}
</script>@endpush
@endsection
