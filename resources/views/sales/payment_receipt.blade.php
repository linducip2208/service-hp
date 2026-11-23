@extends('layouts.tabler')
@section('title', 'Kwitansi ' . $receiptNo)
@section('content')
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <div class="page-pretitle">Penjualan • Kwitansi Pembayaran #{{ $seq }}</div>
            <h2 class="page-title font-monospace">{{ $receiptNo }}</h2>
            <div class="text-secondary">
                Invoice {{ $sale->invoice_no }} • {{ $sale->store->name ?? '' }}
            </div>
        </div>
        <div class="col-auto d-print-none">
            <button type="button" class="btn btn-success" onclick="window.print()">Cetak</button>
            <a href="{{ route('sales.show', $sale) }}" class="btn btn-outline-secondary">Kembali</a>
        </div>
    </div>
</div>
<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <div class="text-center mb-3">
                    <div class="fw-bold fs-3">KWITANSI PEMBAYARAN</div>
                    <div class="font-monospace text-secondary">{{ $receiptNo }}</div>
                    <div class="small text-secondary">{{ $sale->store->name ?? '' }}</div>
                </div>
                <table class="table table-sm mb-3">
                    <tbody>
                        <tr>
                            <th class="w-25">No. Kwitansi</th>
                            <td class="font-monospace">{{ $receiptNo }}</td>
                        </tr>
                        <tr>
                            <th>Invoice</th>
                            <td class="font-monospace">{{ $sale->invoice_no }}</td>
                        </tr>
                        <tr>
                            <th>Pelanggan</th>
                            <td>{{ $sale->customer->name ?? 'Umum' }}</td>
                        </tr>
                        <tr>
                            <th>Jumlah Bayar</th>
                            <td>
                                <b>Rp {{ number_format($payment->amount, 0, ',', '.') }}</b>
                                <span class="badge bg-blue-lt ms-1">{{ strtoupper($payment->method) }}</span>
                            </td>
                        </tr>
                        <tr>
                            <th>Tanggal Bayar</th>
                            <td>{{ $payment->created_at->format('d/m/Y H:i') }}</td>
                        </tr>
                        <tr>
                            <th>Diterima Oleh</th>
                            <td>{{ $payment->user->name ?? '—' }}</td>
                        </tr>
                        @if($payment->note)
                            <tr>
                                <th>Catatan</th>
                                <td>{{ $payment->note }}</td>
                            </tr>
                        @endif
                        <tr>
                            <th>Total Tagihan</th>
                            <td>Rp {{ number_format($sale->total, 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <th>Dibayar s/d Ini</th>
                            <td>Rp {{ number_format($cumulative, 0, ',', '.') }} (pembayaran ke-{{ $seq }})</td>
                        </tr>
                        <tr>
                            <th>Sisa Setelah Ini</th>
                            <td>
                                <b>Rp {{ number_format($sisaSetelah, 0, ',', '.') }}</b>
                                @if($sisaSetelah <= 0)
                                    <span class="badge bg-green-lt ms-1">LUNAS</span>
                                @endif
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div class="row mt-4">
                    <div class="col-6 text-center">
                        <div class="small text-secondary">Penerima,</div>
                        <div style="height: 64px;"></div>
                        <div>( {{ $payment->user->name ?? '....................' }} )</div>
                    </div>
                    <div class="col-6 text-center">
                        <div class="small text-secondary">Penyetor,</div>
                        <div style="height: 64px;"></div>
                        <div>( {{ $sale->customer->name ?? '....................' }} )</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
