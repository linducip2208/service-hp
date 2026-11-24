@extends('layouts.tabler')
@section('title','CRM Pelanggan')
@section('content')
<a href="{{ route('customers.index') }}" class="btn btn-outline-secondary btn-sm mb-3 d-print-none">← Kembali</a>
<div class="mb-3 d-print-none">
    <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="ti ti-printer me-1"></i> Cetak</button>
</div>
<div class="row row-cards print-area">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">{{ $customer->name }}</h3>
            </div>
            <div class="card-body">
                <p class="mb-1">HP: {{ $customer->phone ?? '-' }}</p>
                <p class="mb-1">Member: {{ $customer->member_code ?? '-' }}</p>
                @php
                    $tier = \App\Http\Controllers\CustomerController::tierOf((float) $totalBelanja);
                @endphp
                <p class="mb-1">
                    Tier:
                    <span class="badge bg-{{ $tier == 'Gold' ? 'yellow' : ($tier == 'Silver' ? 'blue' : 'gray') }}-lt">{{ $tier }}</span>
                    <span class="text-secondary small">(Bronze &lt;1jt • Silver 1–5jt • Gold &gt;5jt)</span>
                </p>
                <p class="mb-1">Toko: {{ $customer->store->name ?? '-' }}</p>
                <p>Total belanja: <b>Rp {{ number_format($totalBelanja, 0, ',', '.') }}</b></p>
                <a href="{{ route('customers.edit', $customer) }}" class="btn btn-sm btn-secondary">Edit</a>
                <a
                    href="{{ route('customers.member-card', $customer) }}"
                    class="btn btn-sm btn-outline-primary"
                    target="_blank"
                >
                    <i class="ti ti-id me-1"></i> Kartu Member
                </a>
                <a
                    href="{{ route('customers.vcard', $customer) }}"
                    class="btn btn-sm btn-outline-secondary"
                >
                    <i class="ti ti-download me-1"></i> Unduh Kontak (.vcf)
                </a>
                <form
                    method="POST"
                    action="{{ route('customers.destroy', $customer) }}"
                    class="d-inline"
                    onsubmit="return confirm('Hapus pelanggan?')"
                >
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-sm btn-outline-danger">Hapus</button>
                </form>
            </div>
        </div>
        <div
            class="card mt-3"
            id="ringkasan-rfm"
        >
            <div class="card-header">
                <h3
                    class="card-title"
                >
                    Ringkasan RFM
                </h3>
            </div>
            <div
                class="card-body"
            >
                <p
                    class="mb-1"
                >
                    Transaksi terakhir:
                    @if($rfmLastDate)
                        <b>{{ $rfmLastDate->format('d/m/Y') }}</b>
                        <span
                            class="badge bg-blue-lt"
                        >
                            {{ $rfmDaysAgo }} hari lalu
                        </span>
                    @else
                        <span
                            class="badge bg-gray-lt"
                        >
                            Belum ada transaksi
                        </span>
                    @endif
                </p>
                <p
                    class="mb-1"
                >
                    Jumlah transaksi:
                    <span
                        class="badge bg-azure-lt"
                    >
                        {{ $rfmCount }} invoice
                    </span>
                </p>
                <p
                    class="mb-1"
                >
                    Rata-rata belanja:
                    <b>Rp {{ number_format($rfmAvg, 0, ',', '.') }}</b>
                </p>
                <p
                    class="mb-0"
                >
                    Status:
                    @if($rfmIsDormant)
                        <span
                            class="badge bg-red-lt"
                        >
                            Dorman
                        </span>
                        <span
                            class="text-secondary small"
                        >
                            (&gt;90 hari tanpa transaksi)
                        </span>
                    @else
                        <span
                            class="badge bg-green-lt"
                        >
                            Aktif
                        </span>
                        <span
                            class="text-secondary small"
                        >
                            (transaksi ≤90 hari terakhir)
                        </span>
                    @endif
                </p>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div
            class="card mb-3"
            id="rekap-bulan-ini"
        >
            <div class="card-header">
                <h3 class="card-title">
                    Rekap {{ $monthLabel }} — {{ $monthlySales->count() }} invoice
                </h3>
            </div>
            <div class="card-body border-bottom d-print-none">
                <form
                    method="GET"
                    action="{{ route('customers.show', $customer) }}"
                    class="row g-2 align-items-end"
                >
                    <div class="col-auto">
                        <label
                            class="form-label"
                            for="rekap-from"
                        >
                            Dari
                        </label>
                        <input
                            type="date"
                            id="rekap-from"
                            name="from"
                            class="form-control form-control-sm"
                            value="{{ $fromStr ?? request('from') }}"
                        >
                    </div>
                    <div class="col-auto">
                        <label
                            class="form-label"
                            for="rekap-to"
                        >
                            Sampai
                        </label>
                        <input
                            type="date"
                            id="rekap-to"
                            name="to"
                            class="form-control form-control-sm"
                            value="{{ $toStr ?? request('to') }}"
                        >
                    </div>
                    <div class="col-auto">
                        <button
                            type="submit"
                            class="btn btn-sm btn-primary"
                        >
                            Terapkan
                        </button>
                        <a
                            href="{{ route('customers.show', $customer) }}"
                            class="btn btn-sm btn-outline-secondary"
                        >
                            Bulan ini
                        </a>
                    </div>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table card-table">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Tgl</th>
                            <th>Status</th>
                            <th class="text-end">Total</th>
                            <th class="text-end">Dibayar</th>
                            <th class="text-end">Sisa</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($monthlySales as $s)
                            <tr>
                                <td>{{ $s->invoice_no }}</td>
                                <td>{{ $s->created_at->format('d/m/Y') }}</td>
                                <td>{{ $s->payment_status }}</td>
                                <td class="text-end">{{ number_format($s->total, 0, ',', '.') }}</td>
                                <td class="text-end">{{ number_format($s->paid_amount, 0, ',', '.') }}</td>
                                <td class="text-end">{{ number_format($s->remaining, 0, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-secondary">Belum ada invoice pada rentang ini.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if($monthlySales->isNotEmpty())
                        <tfoot>
                            <tr>
                                <th colspan="3">Total tagihan</th>
                                <th class="text-end">{{ number_format($monthTotal, 0, ',', '.') }}</th>
                                <th class="text-end">{{ number_format($monthPaid, 0, ',', '.') }}</th>
                                <th class="text-end">{{ number_format($monthRemain, 0, ',', '.') }}</th>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
            <div class="card-footer d-flex gap-2 d-print-none">
                <button
                    class="btn btn-sm btn-outline-secondary"
                    onclick="window.print()"
                >
                    <i class="ti ti-printer me-1"></i> Cetak rekap
                </button>
                <form
                    method="POST"
                    action="{{ route('customers.statement.wa', $customer) }}"
                    class="d-inline"
                >
                    @csrf
                    <input
                        type="hidden"
                        name="from"
                        value="{{ $fromStr ?? request('from') }}"
                    >
                    <input
                        type="hidden"
                        name="to"
                        value="{{ $toStr ?? request('to') }}"
                    >
                    <button
                        class="btn btn-sm btn-success"
                        @if($monthlySales->isEmpty()) disabled @endif
                    >
                        Kirim rekap via WA
                    </button>
                </form>
            </div>
        </div>
        <div class="card mb-3">
            <div class="card-header">
                <h3 class="card-title">Riwayat Belanja ({{ $sales->count() }})</h3>
            </div>
            <div class="table-responsive">
                <table class="table card-table">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Tgl</th>
                            <th class="text-end">Total</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($sales as $s)
                            <tr>
                                <td>{{ $s->invoice_no }}</td>
                                <td>{{ $s->created_at->format('d/m/Y') }}</td>
                                <td class="text-end">{{ number_format($s->total, 0, ',', '.') }}</td>
                                <td>{{ $s->payment_status }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-secondary">Belum ada pembelian.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="ti ti-device-mobile me-1"></i>Perangkat / IMEI ({{ $devices->count() }})</h3>
                <div class="card-subtitle">Dari tiket service + unit yang dibeli</div>
            </div>
            <div class="table-responsive">
                <table class="table card-table">
                    <thead>
                        <tr>
                            <th>IMEI</th>
                            <th>Perangkat</th>
                            <th>Sumber</th>
                            <th>Ref</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($devices as $d)
                            <tr>
                                <td class="font-monospace">{{ $d['imei'] }}</td>
                                <td>{{ $d['label'] }}</td>
                                <td><span class="badge bg-{{ $d['source'] === 'pembelian' ? 'green' : 'blue' }}-lt">{{ $d['source'] }}</span></td>
                                <td class="small text-secondary">{{ $d['ref'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-secondary">Belum ada perangkat tercatat.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Riwayat Service ({{ $services->count() }})</h3>
            </div>
            <div class="table-responsive">
                <table class="table card-table">
                    <thead>
                        <tr>
                            <th>Tiket</th>
                            <th>Keluhan</th>
                            <th>Status</th>
                            <th class="text-end">Biaya</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($services as $t)
                            <tr>
                                <td>{{ $t->ticket_no }}</td>
                                <td>{{ \Illuminate\Support\Str::limit($t->complaint, 40) }}</td>
                                <td>{{ $t->status }}</td>
                                <td class="text-end">{{ number_format($t->total_cost, 0, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-secondary">Belum ada service.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
