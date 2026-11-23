@extends('layouts.tabler')
@section('title', 'Detail '.$sale->invoice_no)
@section('content')
<div class="page-header"><div class="row align-items-center"><div class="col"><div class="page-pretitle">Penjualan • Detail Transaksi</div><h2 class="page-title font-monospace">{{ $sale->invoice_no }}</h2>
<div class="text-secondary">{{ $sale->store->name ?? '' }} • {{ $sale->created_at->format('d/m/Y H:i') }} • Kasir: {{ $sale->user->name ?? '' }}</div></div>
<div class="col-auto">
  <a
      href="{{ route('pos.index', ['repeat_sale' => $sale->id]) }}"
      class="btn btn-primary"
      title="Muat item transaksi ini ke POS sebagai transaksi baru (tanpa pembayaran lama). Unit IMEI spesifik tidak dipakai ulang — pilih unit pengganti di POS."
  >
      <i class="ti ti-repeat me-1"></i> Ulangi sebagai Transaksi Baru
  </a>
  <a href="{{ route('pos.print',$sale) }}" class="btn btn-success"><i class="ti ti-printer me-1"></i> Cetak Ulang Struk</a>
  <a href="{{ route('sales.invoice',$sale) }}" class="btn btn-outline-dark" target="_blank">Invoice A4</a>
  @if(in_array($sale->payment_method, ['cicilan', 'dp'], true) || in_array($sale->payment_status, ['cicilan', 'dp'], true))
  <a href="{{ route('sales.agreement',$sale) }}" class="btn btn-outline-primary" target="_blank">Perjanjian Kredit</a>
  @endif
  <a href="{{ route('sales.index') }}" class="btn btn-outline-secondary">Kembali</a>
</div></div></div>
<div class="row g-3">
<div class="col-lg-7"><div class="card"><div class="card-header"><h3 class="card-title">Item</h3></div>
<div class="table-responsive"><table class="table mb-0"><thead><tr><th>Produk</th><th>Qty</th><th class="text-end">Harga</th><th class="text-end">Subtotal</th></tr></thead>
<tbody>@foreach($sale->items as $it)<tr><td>{{ $it->description }}@if($it->productItem)<div class="small font-monospace text-secondary">IMEI: {{ $it->productItem->imei }}</div>@endif</td>
<td>{{ $it->qty }}</td><td class="text-end">{{ number_format($it->price,0,',','.') }}</td><td class="text-end">{{ number_format($it->subtotal,0,',','.') }}</td></tr>@endforeach</tbody></table></div>
<div class="card-body border-top">
<div class="d-flex justify-content-between"><span>Subtotal</span><b>Rp {{ number_format($sale->subtotal,0,',','.') }}</b></div>
<div class="d-flex justify-content-between"><span>Diskon{{ $sale->promo ? ' ('.$sale->promo->code.')' : '' }}</span><b>Rp {{ number_format($sale->discount,0,',','.') }}</b></div>
@if($sale->trade_in_value>0)<div class="d-flex justify-content-between"><span>Tukar tambah</span><b>Rp {{ number_format($sale->trade_in_value,0,',','.') }}</b></div>@endif
<div class="d-flex justify-content-between fs-2"><span>Total</span><b>Rp {{ number_format($sale->total,0,',','.') }}</b></div>
<div class="d-flex justify-content-between"><span>Dibayar ({{ $sale->payment_method }})</span><b>Rp {{ number_format($sale->paid_amount,0,',','.') }}</b></div>
<div class="d-flex justify-content-between"><span>Sisa <span class="badge bg-{{ $sale->payment_status==='lunas'?'green':($sale->payment_status==='belum'?'red':'yellow') }}-lt">{{ strtoupper($sale->payment_status) }}</span></span><b>Rp {{ number_format($sale->remaining,0,',','.') }}</b></div>
<div class="d-flex justify-content-between text-success"><span>Profit invoice</span><b>Rp {{ number_format($sale->items->sum('profit'),0,',','.') }}</b></div>
@php
  $sigPath = null;
  if (preg_match('/\[SIG:([^\]]+)\]/', (string) $sale->note, $sigm)) {
      $sigPath = trim($sigm[1]);
  }
  $noteBersih = trim(preg_replace('/\s*\[SIG:[^\]]+\]/', '', (string) $sale->note ?? ''));
@endphp
@if($noteBersih)
  <div class="text-secondary mt-2">
    Catatan: {{ str_replace('[UCAPAN]', '', $noteBersih) }}
  </div>
@endif
@if($sigPath)
  <div class="mt-2">
    <div class="small text-secondary mb-1">
      TTD persetujuan {{ strtoupper($sale->payment_method) }}
      <span class="badge bg-dark-lt ms-1">bertanda tangan</span>
    </div>
    <a href="{{ asset('storage/' . $sigPath) }}" target="_blank">
      <img
        src="{{ asset('storage/' . $sigPath) }}"
        alt="TTD persetujuan"
        style="max-height: 90px; background: #fff; border: 1px solid #e5e7eb; border-radius: 6px; padding: 2px;"
      >
    </a>
    <div>
      <a href="{{ asset('storage/' . $sigPath) }}" target="_blank" class="small">Lihat TTD</a>
    </div>
  </div>
@endif
</div></div>
@if($sale->returns->count())
    <div class="card mt-3">
        <div class="card-header">
            <h3 class="card-title">Retur ({{ $sale->returns->count() }}x)</h3>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>Tipe</th>
                        <th class="text-end">Jumlah Kembali</th>
                        <th>Alasan</th>
                        <th class="d-print-none">Bukti</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($sale->returns as $r)
                        <tr>
                            <td class="small">{{ $r->created_at->format('d/m/Y H:i') }}</td>
                            <td>
                                <span class="badge bg-orange-lt">{{ strtoupper($r->type) }}</span>
                            </td>
                            <td class="text-end">Rp {{ number_format($r->amount, 0, ',', '.') }}</td>
                            <td class="small">{{ $r->reason ?: '—' }}</td>
                            <td class="d-print-none">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-dark"
                                    onclick="printStruk('retur-struk-{{ $r->id }}')"
                                >
                                    <i class="ti ti-printer me-1"></i> Cetak
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    {{-- Area cetak bukti retur/refund per baris (pakai data baris, tanpa route baru). --}}
    @foreach($sale->returns as $r)
        <div
            id="retur-struk-{{ $r->id }}"
            style="display:none"
        >
            <div style="font-family:monospace;font-size:12px;width:58mm;color:#000">
                <div style="text-align:center;font-weight:bold;font-size:14px">
                    BUKTI {{ strtoupper($r->type) }}
                </div>
                <div style="text-align:center">
                    {{ $sale->store->name ?? '' }}
                </div>
                <div style="text-align:center">
                    No. RTR-{{ $r->id }}
                </div>
                <hr>
                <div>
                    Nota asal: {{ $sale->invoice_no }}
                </div>
                <div>
                    Tanggal retur: {{ $r->created_at->format('d/m/Y H:i') }}
                </div>
                <div>
                    Alasan: {{ $r->reason ?: '-' }}
                </div>
                <div>
                    Kasir: {{ $r->user->name ?? $sale->user->name ?? '-' }}
                </div>
                <hr>
                <div style="font-size:14px;font-weight:bold">
                    Jumlah kembali: Rp {{ number_format($r->amount, 0, ',', '.') }}
                </div>
                <hr>
                <div style="display:flex;justify-content:space-between;margin-top:24px">
                    <div style="text-align:center">
                        Penerima dana,
                        <br><br><br>
                        ( .................... )
                    </div>
                    <div style="text-align:center">
                        Kasir,
                        <br><br><br>
                        ( {{ $r->user->name ?? $sale->user->name ?? '....................' }} )
                    </div>
                </div>
                <div style="text-align:center;margin-top:8px">
                    Simpan bukti ini sebagai arsip toko.
                </div>
            </div>
        </div>
    @endforeach
@endif
@if(!empty($installmentSchedule))
    <div
        class="card mt-3"
        id="jadwal-angsuran"
    >
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title mb-0">
                Jadwal Angsuran ({{ count($installmentSchedule) }}x)
            </h3>
            <button
                type="button"
                class="btn btn-sm btn-outline-secondary d-print-none"
                onclick="if (typeof printStruk === 'function') { printStruk('jadwal-struk-{{ $sale->id }}'); } else { document.body.classList.add('print-jadwal-only'); window.print(); setTimeout(function () { document.body.classList.remove('print-jadwal-only'); }, 500); }"
            >
                <i class="ti ti-printer me-1"></i> Cetak Jadwal
            </button>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th style="width:40px">Ke</th>
                        <th>Jatuh Tempo</th>
                        <th class="text-end">Nominal</th>
                        <th class="text-end">Kumulatif</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($installmentSchedule as $row)
                        <tr>
                            <td>{{ $row['n'] }}</td>
                            <td>
                                {{ $row['due']->format('d/m/Y') }}
                                <div class="small text-secondary">
                                    {{ $row['due']->translatedFormat('l') }}
                                </div>
                            </td>
                            <td class="text-end">
                                Rp {{ number_format($row['nominal'], 0, ',', '.') }}
                            </td>
                            <td class="text-end text-secondary">
                                Rp {{ number_format($row['cumulative'], 0, ',', '.') }}
                            </td>
                            <td>
                                @if($row['status'] === 'lunas')
                                    <span class="badge bg-green-lt">LUNAS</span>
                                @else
                                    <span class="badge bg-yellow-lt">BELUM</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="2">Total jadwal</th>
                        <th class="text-end">
                            Rp {{ number_format(collect($installmentSchedule)->sum('nominal'), 0, ',', '.') }}
                        </th>
                        <th colspan="2" class="small text-secondary">
                            Dibayar Rp {{ number_format($sale->payments->sum('amount'), 0, ',', '.') }}
                        </th>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="card-body border-top small text-secondary">
            Estimasi tampilan: nominal per bulan =
            total &divide; tenor (dibulatkan ke rupiah),
            kecuali angsuran terakhir = total &minus;
            jumlah angsuran sebelumnya (selisih pembulatan).
            Status per baris lunas bila
            total bayar kumulatif &ge; kumulatif jadwal s.d. baris itu.
        </div>
    </div>
    <style>
        @media print {
            body.print-jadwal-only * {
                visibility: hidden;
            }
            body.print-jadwal-only #jadwal-angsuran,
            body.print-jadwal-only #jadwal-angsuran * {
                visibility: visible;
            }
            body.print-jadwal-only #jadwal-angsuran {
                position: absolute;
                inset: 0;
            }
        }
    </style>
    {{-- Area cetak jadwal saja (pola printStruk existing): kop + tabel jadwal,
        tombol di kartu memanggil printStruk(id ini). --}}
    <div
        id="jadwal-struk-{{ $sale->id }}"
        style="display:none"
    >
        <div style="font-family:Arial,Helvetica,sans-serif;color:#000;max-width:640px;margin:0 auto">
            <div style="text-align:center;margin-bottom:12px">
                <div style="font-size:20px;font-weight:bold">
                    JADWAL ANGSURAN
                </div>
                <div style="font-size:12px">
                    {{ $sale->invoice_no }} • {{ $sale->store->name ?? '' }}
                </div>
                <div style="font-size:12px">
                    {{ $sale->customer->name ?? 'Umum' }} • {{ $sale->created_at->format('d/m/Y H:i') }}
                </div>
            </div>
            <table
                style="width:100%;border-collapse:collapse;font-size:13px"
                border="1"
                cellpadding="6"
            >
                <thead>
                    <tr>
                        <th style="width:36px">Ke</th>
                        <th style="text-align:left">Jatuh Tempo</th>
                        <th style="text-align:right">Nominal</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($installmentSchedule as $row)
                        <tr>
                            <td style="text-align:center">{{ $row['n'] }}</td>
                            <td>{{ $row['due']->format('d/m/Y') }}</td>
                            <td style="text-align:right">
                                Rp {{ number_format($row['nominal'], 0, ',', '.') }}
                            </td>
                            <td style="text-align:center">
                                {{ strtoupper($row['status']) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div style="font-size:13px;margin-top:8px">
                Total jadwal: Rp {{ number_format(collect($installmentSchedule)->sum('nominal'), 0, ',', '.') }}
                <br>
                Dibayar: Rp {{ number_format($sale->payments->sum('amount'), 0, ',', '.') }}
                • Sisa: Rp {{ number_format($sale->remaining, 0, ',', '.') }}
            </div>
        </div>
    </div>
@endif
</div>
<div class="col-lg-5">
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Riwayat Pembayaran Lengkap ({{ $sale->payments->count() }}x)</h3>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th>Waktu</th>
                    <th>Metode</th>
                    <th>Kasir</th>
                    <th class="text-end">Jumlah</th>
                    <th class="text-end d-print-none">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($sale->payments as $p)
                    @php
                        $buktiPath = null;
                        if (preg_match('/\[BUKTI:(.*?)\]/', $p->note ?? '', $m)) {
                            $buktiPath = trim($m[1]);
                        }
                        $noteBersih = trim(preg_replace('/\[BUKTI:.*?\]/', '', $p->note ?? ''));
                    @endphp
                    <tr>
                        <td class="small">{{ $p->created_at->format('d/m/Y H:i') }}</td>
                        <td>
                            <span class="badge bg-blue-lt">{{ strtoupper($p->method) }}</span>
                            @if($noteBersih)
                                <div class="small text-secondary">{{ $noteBersih }}</div>
                            @endif
                            @if($buktiPath)
                                <div class="mt-1">
                                    <a href="{{ asset('storage/' . $buktiPath) }}" target="_blank">
                                        <img
                                            src="{{ asset('storage/' . $buktiPath) }}"
                                            alt="Bukti transfer"
                                            class="rounded border"
                                            style="width: 72px; height: 72px; object-fit: cover;"
                                            loading="lazy"
                                        >
                                    </a>
                                    <div>
                                        <a href="{{ asset('storage/' . $buktiPath) }}" target="_blank" class="small">Lihat bukti</a>
                                    </div>
                                </div>
                            @endif
                        </td>
                        <td class="small">{{ $p->user->name ?? '—' }}</td>
                        <td class="text-end">{{ number_format($p->amount, 0, ',', '.') }}</td>
                        <td class="text-end d-print-none">
                            <a href="{{ route('sales.payments.receipt', $p) }}" class="btn btn-sm btn-outline-dark" target="_blank">
                                Kwitansi
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-secondary">Belum ada pembayaran tercatat.</td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="3">Total dibayar</th>
                    <th class="text-end">Rp {{ number_format($sale->payments->sum('amount'), 0, ',', '.') }}</th>
                    <th></th>
                </tr>
            </tfoot>
        </table>
    </div>
    @if($sale->remaining > 0)
        <div class="card-body border-top">
            <form method="POST" action="{{ route('sales.pay', $sale) }}" enctype="multipart/form-data" onsubmit="return confirm('Tambah pembayaran ini?')">
                @csrf
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label">Jumlah</label>
                        <input name="amount" type="number" min="1" max="{{ $sale->remaining }}" value="{{ $sale->remaining }}" class="form-control" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Metode</label>
                        <select name="method" class="form-select">
                            <option value="tunai">Tunai</option>
                            <option value="transfer">Transfer</option>
                            <option value="qris">QRIS</option>
                            <option value="cicilan">Cicilan</option>
                            <option value="dp">DP</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Catatan</label>
                        <input name="note" class="form-control">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Bukti transfer (opsional, JPG/PNG maks 2MB)</label>
                        <input name="bukti" type="file" accept="image/*" class="form-control">
                    </div>
                    <div class="col-12">
                        <button class="btn btn-success w-100">Tambah Pembayaran</button>
                    </div>
                </div>
            </form>
        </div>
    @endif
</div>
</div></div>
@endsection
