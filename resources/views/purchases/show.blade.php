@extends('layouts.tabler')
@section('title','Detail Pembelian')
@section('content')
@php
    $repeatParams = ['supplier_id' => $purchase->supplier_id];
    foreach ($purchase->items as $rit) {
        $repeatParams['product_id'][] = $rit->product_id;
        $repeatParams['qty'][] = (int) $rit->qty;
        $repeatParams['price'][] = (float) $rit->price;
    }
    $repeatUrl = route('purchases.index', $repeatParams);
@endphp
<a href="{{ route('purchases.index') }}" class="btn btn-outline-secondary btn-sm mb-3 d-print-none">← Kembali</a>
<a
    href="{{ $repeatUrl }}"
    class="btn btn-outline-primary btn-sm mb-3 ms-1 d-print-none"
    title="Buat pembelian baru dengan supplier & item yang sama"
><i class="ti ti-repeat me-1"></i> Ulangi Pembelian</a>
<div class="mb-3 d-print-none"><button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="ti ti-printer me-1"></i> Cetak</button>
  <button class="btn btn-success btn-sm" onclick="if(confirm('Kirim pesanan ini via WA ke supplier {{ addslashes($purchase->supplier->name ?? '-') }} ({{ addslashes($purchase->supplier->phone ?? '-') }})?')){document.getElementById('wa-order-form').submit();}"><i class="ti ti-brand-whatsapp me-1"></i> Pesan via WA</button></div>
<form id="wa-order-form" method="POST" action="{{ route('purchases.wa', $purchase) }}" class="d-none">@csrf</form>
<div class="card print-area"><div class="card-header"><h3 class="card-title">Pembelian {{ $purchase->invoice_no }}</h3>
<span class="badge bg-blue-lt ms-2">{{ $purchase->status }}</span>
@if($received)<span class="badge bg-green-lt ms-1">✓ Diterima</span>@else<span class="badge bg-yellow-lt ms-1">Belum ditandai diterima</span>@endif</div>
<div class="card-body">
  <p class="mb-1">Supplier: <b>{{ $purchase->supplier->name ?? '-' }}</b> | Total: <b>Rp {{ number_format($purchase->total,0,',','.') }}</b> | Dibayar: <b>Rp {{ number_format($purchase->paid,0,',','.') }}</b> | Sisa: <b>Rp {{ number_format($purchase->total-$purchase->paid,0,',','.') }}</b></p>
  @php
      $notaFotoPath = null;
      if (preg_match('/\[BUKTI:(.*?)\]/', (string) $purchase->note, $mNota)) {
          $notaFotoPath = trim($mNota[1]);
      } elseif (preg_match('/\[FOTO:([^\]]+)\]/', (string) $purchase->note, $mNota)) {
          $notaFotoPath = trim($mNota[1]);
      }
      $notaBersih = trim(preg_replace('/\s*\[(BUKTI|FOTO):[^\]]+\]/', '', (string) $purchase->note));
  @endphp
  @if($notaBersih)
      <p class="text-secondary">{{ $notaBersih }}</p>
  @endif
  @if($notaFotoPath)
      <div class="mt-1 mb-2">
          <div class="form-label">Foto Nota Supplier</div>
          <a
              href="{{ asset('storage/' . $notaFotoPath) }}"
              target="_blank"
          >
              <img
                  src="{{ asset('storage/' . $notaFotoPath) }}"
                  alt="Foto nota supplier"
                  class="rounded border"
                  style="width: 160px; height: 160px; object-fit: cover;"
                  loading="lazy"
              >
          </a>
          <div>
              <a
                  href="{{ asset('storage/' . $notaFotoPath) }}"
                  target="_blank"
                  class="small"
              >Lihat foto nota</a>
          </div>
      </div>
  @endif
  @if($debt)<div class="alert alert-warning">Hutang terkait: Rp {{ number_format($debt->remaining,0,',','.') }} ({{ $debt->status }}) — cicil via menu <a href="{{ route('debts.index') }}">Hutang & Piutang</a> atau form Bayar di bawah.</div>@endif
  @if(!$received)
  <form method="POST" action="{{ route('purchases.received', $purchase) }}" class="d-print-none mb-3" onsubmit="return confirm('Tandai barang pembelian ini sudah diterima?')">@csrf
    <button class="btn btn-sm btn-outline-success"><i class="ti ti-package me-1"></i> Tandai Diterima</button>
    <span class="text-secondary small ms-1">Stok sudah masuk otomatis saat pembelian disimpan; tombol ini hanya penanda penerimaan fisik (tanpa kolom baru — tersimpan di catatan).</span>
  </form>
  @endif
  @php $sisa = (float) $purchase->total - (float) $purchase->paid; @endphp
  @if($sisa > 0)
  <div class="card card-sm mb-3 d-print-none">
    <div class="card-header">
      <h4 class="card-title"><i class="ti ti-cash me-1"></i> Bayar Hutang Supplier</h4>
    </div>
    <div class="card-body">
      <form
        method="POST"
        action="{{ route('purchases.pay', $purchase) }}"
        enctype="multipart/form-data"
        class="row g-2 align-items-end"
        onsubmit="return confirm('Catat pembayaran hutang ini? Kas berkurang & status diperbarui.')"
      >
        @csrf
        <div class="col-md-3">
          <label class="form-label">Nominal (Rp)</label>
          <input
            type="number"
            name="amount"
            class="form-control"
            min="1"
            max="{{ $sisa }}"
            value="{{ $sisa }}"
            required
          >
        </div>
        <div class="col-md-4">
          <label class="form-label">Akun Kas</label>
          <select name="cash_account_id" class="form-select" required>
            @foreach($accounts as $a)
              <option value="{{ $a->id }}">{{ $a->name }} ({{ number_format($a->balance,0,',','.') }})</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Bukti transfer (opsional, JPG/PNG maks 2MB)</label>
          <input
            type="file"
            name="bukti"
            accept="image/*"
            class="form-control"
          >
        </div>
        <div class="col-md-2">
          <span class="text-secondary small d-block mb-1">Sisa hutang: Rp {{ number_format($sisa,0,',','.') }}</span>
          <button class="btn btn-primary btn-sm">Bayar</button>
        </div>
      </form>
      <div class="text-secondary small mt-1">Mencatat kas keluar + mengurangi hutang terkait + update lunas/sebagian pembelian & saldo hutang supplier.</div>
    </div>
  </div>
  @endif
  <h4 class="mt-3"><i class="ti ti-receipt me-1"></i> Riwayat Pembayaran Hutang ({{ ($payHistory ?? collect())->count() }}x)</h4>
  <div class="table-responsive">
    <table class="table table-sm">
      <thead>
        <tr>
          <th>Waktu</th>
          <th>Akun Kas</th>
          <th>Kasir</th>
          <th>Bukti</th>
          <th class="text-end">Jumlah</th>
        </tr>
      </thead>
      <tbody>
        @forelse(($payHistory ?? collect()) as $ph)
          @php
            $phBukti = null;
            if (preg_match('/\[BUKTI:(.*?)\]/', (string) ($ph->description ?? ''), $mPh)) {
                $phBukti = trim($mPh[1]);
            }
            $phDesc = trim(preg_replace('/\s*\[BUKTI:[^\]]+\]/', '', (string) ($ph->description ?? '')));
          @endphp
          <tr>
            <td class="small">{{ $ph->created_at?->format('d/m/Y H:i') ?? '-' }}</td>
            <td class="small">{{ $ph->cashAccount->name ?? '-' }}</td>
            <td class="small">{{ $ph->user->name ?? '—' }}</td>
            <td>
              @if($phBukti)
                <a href="{{ asset('storage/' . $phBukti) }}" target="_blank">
                  <img
                    src="{{ asset('storage/' . $phBukti) }}"
                    alt="Bukti bayar supplier"
                    class="rounded border"
                    style="width: 72px; height: 72px; object-fit: cover;"
                    loading="lazy"
                  >
                </a>
                <div>
                  <a href="{{ asset('storage/' . $phBukti) }}" target="_blank" class="small">Lihat bukti</a>
                </div>
              @else
                <span class="text-secondary small">-</span>
              @endif
              @if($phDesc)
                <div class="small text-secondary">{{ $phDesc }}</div>
              @endif
            </td>
            <td class="text-end">Rp {{ number_format($ph->amount, 0, ',', '.') }}</td>
          </tr>
        @empty
          <tr>
            <td colspan="5" class="text-center text-secondary">Belum ada pembayaran hutang tercatat.</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <div class="table-responsive"><table class="table"><thead><tr><th>Produk</th><th class="text-end">Qty</th><th class="text-end">Harga</th><th class="text-end">Subtotal</th><th class="d-print-none">Retur</th></tr></thead>
  <tbody>@foreach($purchase->items as $it)<tr><td>{{ $it->product->name ?? 'Produk #'.$it->product_id }}</td><td class="text-end">{{ $it->qty }}</td><td class="text-end">{{ number_format($it->price,0,',','.') }}</td><td class="text-end">{{ number_format($it->subtotal,0,',','.') }}</td>
  <td class="d-print-none"><form method="POST" action="{{ route('purchases.store') }}" class="d-flex gap-1" onsubmit="return confirm('Retur item ini ke supplier? Stok berkurang.')">@csrf
    <input type="hidden" name="retur_purchase_id" value="{{ $purchase->id }}">
    <input type="hidden" name="purchase_item_id" value="{{ $it->id }}">
    <input type="hidden" name="cash_account_id" value="{{ \App\Models\CashAccount::where('store_id', $purchase->store_id)->orderBy('is_default','desc')->value('id') }}">
    <input type="number" name="retur_qty" min="1" max="{{ $it->qty }}" value="1" class="form-control form-control-sm" style="width:70px" required>
    <button class="btn btn-sm btn-outline-warning">Retur</button></form></td></tr>@endforeach</tbody></table></div>
  @if(!empty($priceStats))
    @php
      $spikes = collect($priceStats)->filter(fn($s) => !empty($s['is_spike']));
      $ambangPersen = round(($priceSpikeThreshold ?? 0.15) * 100);
    @endphp
    @if($spikes->isNotEmpty())
      <div class="alert alert-danger mt-3">
        <div class="fw-bold mb-1">
          <i class="ti ti-alert-triangle me-1"></i> Alert kenaikan harga beli ({{ $ambangPersen }}% di atas rata-rata): {{ $spikes->count() }} produk terindikasi naik.
        </div>
        <ul class="mb-1 ps-3">
          @foreach($spikes as $pid => $st)
            <li>
              <b>{{ $st['product']->name ?? ('Produk #' . $pid) }}</b>
              — terakhir Rp {{ number_format($st['last'], 0, ',', '.') }}
              vs rata-rata Rp {{ number_format($st['avg'], 0, ',', '.') }}
              (+{{ number_format($st['spike_pct'] ?? 0, 1, ',', '.') }}%).
              @if(!empty($st['cheapest_supplier']))
                Supplier termurah 12 bln terakhir:
                <b>{{ $st['cheapest_supplier'] }}</b>
                Rp {{ number_format($st['cheapest_price'], 0, ',', '.') }}.
                Saran: nego ke {{ $purchase->supplier->name ?? 'supplier saat ini' }}
                atau pindah PO ke {{ $st['cheapest_supplier'] }}.
              @else
                Saran: nego ulang ke supplier atau bandingkan penawaran supplier lain.
              @endif
            </li>
          @endforeach
        </ul>
        <div class="small">
          Ambang: harga terakhir &gt; rata-rata + {{ $ambangPersen }}% (indikasi supplier menaikkan harga).
        </div>
      </div>
    @endif
  <h4 class="mt-3"><i class="ti ti-chart-bar me-1"></i> Riwayat Harga Beli (referensi margin)</h4>
  <p class="text-secondary small mb-2">
    Badge merah <i class="ti ti-alert-triangle me-1"></i> muncul bila harga terakhir &gt; rata-rata + {{ $ambangPersen }}%.
  </p>
  <div class="table-responsive">
    <table class="table table-sm">
      <thead>
        <tr>
          <th>Produk</th>
          <th class="text-end">Beli Terakhir</th>
          <th class="text-end">Rata-rata</th>
          <th class="text-end">Batas Wajar (+{{ $ambangPersen }}%)</th>
          <th class="text-end">Status</th>
          <th class="text-end">Harga Jual</th>
          <th class="text-end">Est. Margin (jual − rata2)</th>
          <th>Supplier Termurah</th>
          <th class="text-end">Data</th>
        </tr>
      </thead>
      <tbody>
        @foreach($priceStats as $pid => $st)
          <tr class="{{ !empty($st['is_spike']) ? 'table-danger' : '' }}">
            <td>
              {{ $st['product']->name ?? 'Produk #'.$pid }}
              @if(!empty($st['is_spike']))
                <span class="badge bg-danger ms-1">
                  <i class="ti ti-alert-triangle me-1"></i> Naik +{{ number_format($st['spike_pct'] ?? 0, 1, ',', '.') }}%
                </span>
              @endif
            </td>
            <td class="text-end">
              {{ $st['last'] !== null ? number_format($st['last'],0,',','.') : '-' }}
            </td>
            <td class="text-end">
              {{ $st['avg'] !== null ? number_format($st['avg'],0,',','.') : '-' }}
            </td>
            <td class="text-end text-secondary">
              {{ $st['limit'] !== null ? number_format($st['limit'],0,',','.') : '-' }}
            </td>
            <td class="text-end">
              @if(!empty($st['is_spike']))
                <span class="badge bg-danger">
                  Harga naik
                </span>
                <div class="small text-danger">
                  Nego / pindah supplier
                </div>
              @elseif($st['avg'] !== null)
                <span class="badge bg-green-lt">
                  Normal
                </span>
              @else
                <span class="badge bg-secondary-lt">
                  Data kurang
                </span>
              @endif
            </td>
            <td class="text-end">
              {{ $st['product'] ? number_format($st['product']->selling_price,0,',','.') : '-' }}
            </td>
            <td class="text-end">
              @if($st['product'] && $st['avg'] !== null)
                <b class="{{ ((float)$st['product']->selling_price - (float)$st['avg']) >= 0 ? 'text-success' : 'text-danger' }}">
                  {{ number_format((float)$st['product']->selling_price - (float)$st['avg'],0,',','.') }}
                </b>
              @else
                -
              @endif
            </td>
            <td>
              @if(!empty($st['cheapest_supplier']))
                <div>
                  {{ $st['cheapest_supplier'] }}
                </div>
                <div class="small text-secondary">
                  Rp {{ number_format($st['cheapest_price'], 0, ',', '.') }}
                </div>
                <a
                  href="{{ route('purchases.index', ['supplier_id' => $st['cheapest_supplier_id'], 'product_id' => [$pid], 'qty' => [1], 'price' => [$st['cheapest_price']]]) }}"
                  class="btn btn-sm btn-outline-primary mt-1 d-print-none"
                >
                  Buat PO ke termurah
                </a>
              @else
                <span class="text-secondary small">-</span>
              @endif
            </td>
            <td class="text-end text-secondary">
              {{ $st['count'] }}× beli
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  <div class="card card-sm mt-3 d-print-none">
    <div class="card-header">
      <h4 class="card-title"><i class="ti ti-trending-up me-1"></i> Grafik Harga Beli per Pembelian</h4>
    </div>
    <div class="card-body">
      <div class="row g-2 align-items-end">
        <div class="col-md-5">
          <label class="form-label" for="price-history-select">Produk</label>
          <select id="price-history-select" class="form-select">
            @foreach($priceStats as $pid => $st)
              <option value="{{ $pid }}">{{ $st['product']->name ?? 'Produk #'.$pid }}</option>
            @endforeach
          </select>
        </div>
        <div class="col-md-7">
          <div class="text-secondary small" id="price-history-meta"></div>
        </div>
      </div>
      <div class="mt-2" style="position:relative;height:220px">
        <canvas id="priceHistoryChart"></canvas>
      </div>
      <div class="text-secondary small mt-1">Urut waktu pembelian (maks. 30 data terakhir per produk). Arahkan kursor ke titik untuk detail invoice.</div>
    </div>
  </div>
  @endif
</div></div>
@push('js')
<script src="/vendor/chart/chart.umd.min.js"></script>
<script>
try {
  const priceHistories = @json(collect($priceStats)->mapWithKeys(fn($st, $pid) => [$pid => $st['history'] ?? []]));
  const sel = document.getElementById('price-history-select');
  const meta = document.getElementById('price-history-meta');
  const rp = (v) => 'Rp ' + Number(v || 0).toLocaleString('id-ID');
  let chart = null;
  const renderHistory = () => {
    if (!sel) return;
    const rows = priceHistories[sel.value] || [];
    const labels = rows.map((r) => r.label);
    const prices = rows.map((r) => Number(r.price || 0));
    const invoices = rows.map((r) => r.invoice);
    const name = sel.options[sel.selectedIndex]?.text || '';
    if (meta) {
      meta.textContent = rows.length
        ? name + ': ' + rows.length + '× pembelian, ' + rp(Math.min(...prices)) + ' – ' + rp(Math.max(...prices))
        : name + ': belum ada riwayat.';
    }
    if (chart) chart.destroy();
    chart = new Chart(document.getElementById('priceHistoryChart'), {
      type: 'line',
      data: {
        labels: labels,
        datasets: [{ label: 'Harga beli', data: prices, borderColor: '#206bc4', tension: 0.3, fill: false, pointRadius: 3 }]
      },
      options: {
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom' },
          tooltip: { callbacks: { title: (items) => invoices[items[0]?.dataIndex] ?? '', label: (c) => ' ' + rp(c.parsed.y) } }
        },
        scales: { y: { ticks: { callback: (v) => rp(v) } } }
      }
    });
  };
  if (sel) {
    sel.addEventListener('change', renderHistory);
    renderHistory();
  }
} catch (e) { console.error(e); }
</script>
@endpush
@endsection
