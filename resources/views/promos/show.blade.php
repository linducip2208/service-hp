@extends('layouts.tabler')
@section('title','Detail Promo')
@push('css')
<style>
    @media print {
        body * {
            visibility: hidden !important;
        }
        #kartu-qr-promo,
        #kartu-qr-promo * {
            visibility: visible !important;
        }
        #kartu-qr-promo {
            position: absolute !important;
            left: 0;
            top: 0;
            width: 100% !important;
            margin: 0 !important;
            border: none !important;
            box-shadow: none !important;
        }
    }
</style>
@endpush
@section('content')
<a href="{{ route('promos.index') }}" class="btn btn-outline-secondary btn-sm mb-3">← Kembali</a>
<div class="row row-cards"><div class="col-lg-4"><div class="card"><div class="card-header"><h3 class="card-title">{{ $promo->code }}</h3></div>
<div class="card-body"><p class="mb-1">{{ $promo->name }} ({{ $promo->type }})</p>
<p class="mb-1">Nilai: <b>{{ number_format($promo->value,0,',','.') }}</b> | Min. belanja: {{ number_format($promo->min_belanja,0,',','.') }}</p>
<p class="mb-1">Periode: {{ $promo->start_date?->format('d/m/Y') ?? '–' }} s/d {{ $promo->end_date?->format('d/m/Y') ?? '–' }}</p>
<p class="mb-1">Quota: {{ $promo->quota>0 ? $promo->used.'/'.$promo->quota.' (sisa '.$sisaQuota.')' : 'Tanpa batas (terpakai '.$promo->used.')' }}</p>
<p>Status tanggal: {!! $validTanggal?'<span class="badge bg-green-lt">Dalam periode</span>':'<span class="badge bg-red-lt">Di luar periode</span>' !!}
Kesimpulan: {!! $masihBerlaku?'<span class="badge bg-green-lt">MASIH BERLAKU</span>':'<span class="badge bg-red-lt">TIDAK BERLAKU</span>' !!}</p>
<a href="{{ route('promos.edit',$promo) }}" class="btn btn-sm btn-secondary">Edit</a>
<form method="POST" action="{{ route('promos.update',$promo) }}" class="d-inline" onsubmit="return confirm('Nonaktifkan promo ini sekarang?')">@csrf @method('PUT')
<input type="hidden" name="code" value="{{ $promo->code }}"><input type="hidden" name="name" value="{{ $promo->name }}">
<input type="hidden" name="type" value="{{ $promo->type }}"><input type="hidden" name="value" value="{{ $promo->value }}">
<input type="hidden" name="min_belanja" value="{{ $promo->min_belanja }}"><input type="hidden" name="quota" value="{{ $promo->quota }}">
<input type="hidden" name="start_date" value="{{ $promo->start_date?->format('Y-m-d') }}"><input type="hidden" name="end_date" value="{{ $promo->end_date?->format('Y-m-d') }}">
<button class="btn btn-sm btn-outline-warning">Nonaktifkan Cepat</button></form>
<form method="POST" action="{{ route('promos.destroy',$promo) }}" class="d-inline" onsubmit="return confirm('Hapus promo?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Hapus</button></form>
</div>
</div>
<div class="card mt-3" id="kartu-qr-promo">
    <div class="card-header">
        <h3 class="card-title">QR Promo — Scan / Ketik di Kasir</h3>
    </div>
    <div class="card-body text-center">
        <div class="text-secondary small mb-2">
            Pelanggan tunjukkan QR ini, kasir ketik kodenya di POS.
        </div>
        <div
            id="qr-promo"
            class="d-inline-block mx-auto my-2"
        ></div>
        <div class="mt-2">
            <span class="h2 mb-0 font-monospace tracking-widest">{{ $promo->code }}</span>
        </div>
        <div class="text-secondary small mt-1">
            Min. belanja Rp {{ number_format($promo->min_belanja, 0, ',', '.') }}
            <br>
            Periode: {{ $promo->start_date?->format('d/m/Y') ?? '–' }} s/d {{ $promo->end_date?->format('d/m/Y') ?? '–' }}
        </div>
        <div
            id="qr-promo-text"
            class="text-secondary small mt-1 font-monospace"
            style="word-break: break-all;"
        >
            {{ $promo->code }}
        </div>
        <div class="btn-list justify-content-center mt-3 d-print-none">
            <button
                type="button"
                class="btn btn-sm btn-outline-primary"
                onclick="PromoQr.salin()"
            >
                Salin Kode
            </button>
            <button
                type="button"
                class="btn btn-sm btn-primary"
                onclick="PromoQr.cetak()"
            >
                Cetak QR
            </button>
        </div>
    </div>
</div>
<div class="card mt-3">
    <div class="card-header">
        <h3 class="card-title"><i class="ti ti-package me-1"></i> Pasangan → Bundling</h3>
    </div>
    <div class="card-body">
        <div class="text-secondary small mb-2">
            Bundling mensyaratkan keranjang berisi ≥2 produk berbeda
            (aturan pasangan A/B spesifik belum didukung kolom yang ada).
        </div>
        <a
            href="{{ route('promos.create', [
                'type' => 'bundling',
                'name' => 'Bundling ' . $promo->name,
                'min_belanja' => (float) $promo->min_belanja > 0 ? $promo->min_belanja : 50000,
            ]) }}"
            class="btn btn-sm btn-primary"
        >
            <i class="ti ti-package me-1"></i> Buat Bundling
        </a>
    </div>
</div>
<div class="card mt-3"><div class="card-header"><h3 class="card-title">Statistik Pemakaian</h3></div>
<div class="card-body p-2"><div class="row g-2 text-center">
<div class="col-4"><div class="text-secondary small">Dipakai</div><div class="h3 mb-0">{{ $statPakai }}×</div></div>
<div class="col-4"><div class="text-secondary small">Total Diskon</div><div class="h3 mb-0 text-danger">Rp {{ number_format($statDiskon,0,',','.') }}</div></div>
<div class="col-4"><div class="text-secondary small">Omzet Terkait</div><div class="h3 mb-0 text-success">Rp {{ number_format($statOmzet,0,',','.') }}</div></div>
</div></div></div>
<div class="card mt-3">
    <div class="card-header">
        <h3 class="card-title">ROI Promo</h3>
    </div>
    <div class="card-body">
        <div class="text-secondary small mb-1">
            Rumus: (Omzet Terkait &minus; Total Diskon) &divide; Total Diskon &times; 100%
        </div>
        @if ($statRoi === null)
            <div class="h2 mb-1">-</div>
            <div class="text-secondary small">
                Belum bisa dihitung karena total diskon masih Rp 0.
            </div>
        @else
            <div class="h2 mb-1 {{ $statRoi >= 0 ? 'text-success' : 'text-danger' }}">
                {{ number_format($statRoi, 1, ',', '.') }}%
            </div>
            <div class="text-secondary small">
                @if ($statRoi > 0)
                    Positif &mdash; promo menghasilkan: omzet terkait melebihi biaya diskon.
                @elseif ($statRoi == 0)
                    Impas &mdash; omzet terkait sama dengan biaya diskon.
                @else
                    Negatif &mdash; biaya diskon melebihi omzet terkait, evaluasi promo.
                @endif
            </div>
        @endif
        <div class="text-secondary small mt-2">
            Omzet Rp {{ number_format($statOmzet, 0, ',', '.') }}
            &minus; Diskon Rp {{ number_format($statDiskon, 0, ',', '.') }}
        </div>
    </div>
</div></div></div>
<div class="col-lg-8">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Pemakaian ({{ $usage->count() }})</h3>
            <div class="card-actions">
                <a
                    href="{{ route('promos.show', [$promo, 'export' => 'csv']) }}"
                    class="btn btn-sm btn-outline-primary"
                >
                    <i class="ti ti-download me-1"></i> Export CSV
                </a>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table card-table">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Pelanggan</th>
                        <th>Tgl</th>
                        <th class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($usage as $s)
                        <tr>
                            <td>{{ $s->invoice_no }}</td>
                            <td>{{ $s->customer->name ?? '-' }}</td>
                            <td>{{ $s->created_at->format('d/m/Y') }}</td>
                            <td class="text-end">{{ number_format($s->total,0,',','.') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-secondary">Belum pernah dipakai.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="card mt-3">
        <div class="card-header">
            <h3 class="card-title">Dipakai terakhir oleh (10 terakhir)</h3>
        </div>
        <div class="table-responsive">
            <table class="table card-table">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Pelanggan</th>
                        <th>Tanggal</th>
                        <th class="text-end">Diskon</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentUsage as $s)
                        <tr>
                            <td>{{ $s->invoice_no }}</td>
                            <td>{{ $s->customer->name ?? '-' }}</td>
                            <td>{{ $s->created_at->format('d/m/Y H:i') }}</td>
                            <td class="text-end">{{ number_format($s->discount, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-secondary">Belum pernah dipakai.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="card mt-3">
        <div class="card-header">
            <h3 class="card-title">Tren Pemakaian — 12 Minggu Terakhir</h3>
        </div>
        <div class="card-body">
            <div class="text-secondary mb-2">
                Jumlah pakai &amp; total diskon per minggu (dari sales).
            </div>
            <canvas id="chartPromoTrend" height="140"></canvas>
        </div>
    </div>
</div>
</div>

@push('js')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
try {
    const rp = (v) => 'Rp ' + Number(v || 0).toLocaleString('id-ID');
    const el = document.getElementById('chartPromoTrend');
    if (el) {
        new Chart(el, {
            data: {
                labels: @json($trendLabels ?? []),
                datasets: [
                    {
                        type: 'bar',
                        label: 'Dipakai (×)',
                        data: @json($trendPakai ?? []),
                        backgroundColor: '#206bc4',
                        yAxisID: 'y'
                    },
                    {
                        type: 'line',
                        label: 'Total diskon (Rp)',
                        data: @json($trendDiskon ?? []),
                        borderColor: '#e74c3c',
                        backgroundColor: '#e74c3c',
                        tension: 0.3,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: (c) => c.datasetIndex === 1
                                ? ' ' + c.dataset.label + ': ' + rp(c.parsed.y)
                                : ' ' + c.dataset.label + ': ' + c.parsed.y + '×'
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 },
                        title: { display: true, text: 'Pakai (×)' }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        grid: { drawOnChartArea: false },
                        ticks: {
                            callback: (v) => rp(v)
                        },
                        title: { display: true, text: 'Diskon (Rp)' }
                    }
                }
            }
        });
    }
} catch (e) {
    console.error(e);
}
</script>
@endpush
@push('js')
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
(function () {
    // QR kode promo: payload = kode promo mentah, supaya hasil scan
    // bisa langsung diketik kasir di POS. Teks di bawah QR jadi fallback
    // bila CDN qrcodejs gagal dimuat (pola yang sama dipakai pos/print).
    var kodePromo = @json($promo->code);
    var el = document.getElementById('qr-promo');
    if (el) {
        try {
            if (typeof QRCode !== 'undefined') {
                new QRCode(el, {
                    text: String(kodePromo || ''),
                    width: 160,
                    height: 160,
                    correctLevel: QRCode.CorrectLevel.M
                });
            } else {
                el.style.display = 'none';
            }
        } catch (e) {
            el.innerHTML = '';
            el.style.display = 'none';
        }
    }
    window.PromoQr = {
        salin: function () {
            var kode = String(kodePromo || '');
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(kode).then(function () {
                    alert('Kode promo disalin: ' + kode);
                }).catch(function () {
                    prompt('Salin kode promo:', kode);
                });
            } else {
                prompt('Salin kode promo:', kode);
            }
        },
        cetak: function () {
            window.print();
        }
    };
})();
</script>
@endpush
@endsection
