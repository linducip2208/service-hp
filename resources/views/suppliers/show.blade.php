@extends('layouts.tabler')
@section('title','Detail Supplier')
@section('content')
<a href="{{ route('suppliers.index') }}" class="btn btn-outline-secondary btn-sm mb-3">← Kembali</a>
<div class="row row-cards">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">{{ $supplier->name }}</h3>
            </div>
            <div class="card-body">
                <p class="mb-1">
                    Kode: {{ $supplier->code }} | Telp: {{ $supplier->phone ?? '-' }}
                </p>
                <p class="mb-1">
                    Alamat: {{ $supplier->address ?? '-' }}
                </p>
                <p class="mb-1">
                    Hutang tercatat:
                    <b class="text-danger">Rp {{ number_format($supplier->hutang,0,',','.') }}</b>
                </p>
                <p>
                    Sisa pembelian belum lunas:
                    <b>Rp {{ number_format($outstanding,0,',','.') }}</b>
                </p>
                <a
                    href="{{ route('suppliers.edit', $supplier) }}"
                    class="btn btn-sm btn-secondary"
                >
                    Edit
                </a>
                <a
                    href="{{ route('suppliers.vcard', $supplier) }}"
                    class="btn btn-sm btn-outline-primary"
                >
                    Unduh vCard
                </a>
                <form
                    method="POST"
                    action="{{ route('suppliers.destroy', $supplier) }}"
                    class="d-inline"
                    onsubmit="return confirm('Hapus supplier?')"
                >
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-sm btn-outline-danger">
                        Hapus
                    </button>
                </form>
            </div>
        </div>
        @if($debts->count())
            <div class="card mt-3">
                <div class="card-header">
                    <h3 class="card-title">Hutang Terkait (retur/cicilan via Hutang)</h3>
                </div>
                <div class="table-responsive">
                    <table class="table card-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th class="text-end">Sisa</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($debts as $d)
                                <tr>
                                    <td>{{ $d->ref_type }} #{{ $d->ref_id }}</td>
                                    <td class="text-end">{{ number_format($d->remaining, 0, ',', '.') }}</td>
                                    <td>{{ $d->status }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
        <div class="card mt-3">
            <div class="card-header">
                <h3 class="card-title">
                    Skor Supplier
                    <span class="text-secondary fw-normal">(toko aktif)</span>
                </h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table card-table table-vcenter">
                        <tbody>
                            <tr>
                                <td>Total pembelian (semua waktu)</td>
                                <td class="text-end">
                                    <b>Rp {{ number_format($score['total_all'] ?? 0, 0, ',', '.') }}</b>
                                </td>
                            </tr>
                            <tr>
                                <td>Total pembelian (bulan ini)</td>
                                <td class="text-end">
                                    Rp {{ number_format($score['total_month'] ?? 0, 0, ',', '.') }}
                                </td>
                            </tr>
                            <tr>
                                <td>Jumlah nota</td>
                                <td class="text-end">{{ number_format($score['nota_count'] ?? 0, 0, ',', '.') }}</td>
                            </tr>
                            <tr>
                                <td>Rata-rata nilai nota</td>
                                <td class="text-end">
                                    Rp {{ number_format($score['avg_nota'] ?? 0, 0, ',', '.') }}
                                </td>
                            </tr>
                            <tr>
                                <td>Total retur (nominal)</td>
                                <td class="text-end text-secondary">
                                    –
                                    <small>({{ $score['retur_count'] ?? 0 }} kejadian, {{ $score['retur_qty'] ?? 0 }} pcs)</small>
                                </td>
                            </tr>
                            <tr>
                                <td>Outstanding hutang</td>
                                <td class="text-end text-danger">
                                    Rp {{ number_format($outstanding, 0, ',', '.') }}
                                </td>
                            </tr>
                            <tr>
                                <td>Pembelian terakhir</td>
                                <td class="text-end">
                                    @if(!empty($score['last']))
                                        <a href="{{ route('purchases.show', $score['last']) }}">
                                            {{ $score['last']->invoice_no }}
                                        </a>
                                        <span class="text-secondary">
                                            ({{ $score['last']->created_at?->format('d/m/Y H:i') }})
                                        </span>
                                    @else
                                        <span class="text-secondary">–</span>
                                    @endif
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer text-secondary small">
                Retur dihitung dari stock_movements (keluar/ref retur_beli → purchase → supplier ini);
                nominal Rp tidak tersimpan di movement sehingga ditampilkan "–",
                hanya frekuensi &amp; qty yang jujur dari data existing.
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Riwayat Pembelian</h3>
            </div>
            <div class="table-responsive">
                <table class="table card-table">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th class="text-end">Total</th>
                            <th class="text-end">Dibayar</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($purchases as $p)
                            <tr>
                                <td>
                                    <a href="{{ route('purchases.show', $p) }}">{{ $p->invoice_no }}</a>
                                </td>
                                <td class="text-end">{{ number_format($p->total, 0, ',', '.') }}</td>
                                <td class="text-end">{{ number_format($p->paid, 0, ',', '.') }}</td>
                                <td>{{ $p->status }}</td>
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
    </div>
</div>
@endsection
