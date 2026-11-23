@extends('layouts.tabler')
@section('title','Kalender Booking')
@section('content')
<div class="page-header"><div class="row align-items-center"><div class="col">
<div class="page-pretitle">Service • Booking</div>
<h2 class="page-title">Kalender Booking — {{ $month->locale('id')->isoFormat('MMMM YYYY') }}</h2>
<div class="text-secondary">Dipetakan per tanggal jadwal (fallback tanggal dibuat bila jadwal kosong). Scope toko aktif.</div>
</div><div class="col-auto ms-auto d-flex gap-2 no-print">
<a href="{{ route('bookings.index') }}" class="btn btn-outline-secondary">← Kembali ke List</a>
<button type="button" class="btn btn-outline-dark" onclick="printArea('printBulan')"><i class="ti ti-printer me-1"></i> Cetak Bulan</button>
<button type="button" class="btn btn-dark" onclick="printArea('printHarian')"><i class="ti ti-printer me-1"></i> Cetak Hari Ini</button>
</div></div></div>

<div class="card mt-2 no-print"><div class="card-body d-flex align-items-center gap-2">
<a href="{{ route('bookings.calendar',['bulan'=>$prevMonth]) }}" class="btn btn-outline-primary">‹</a>
<form method="GET" action="{{ route('bookings.calendar') }}" class="d-flex gap-2 align-items-center">
<input type="month" name="bulan" value="{{ $month->format('Y-m') }}" class="form-control" style="max-width:200px">
<button class="btn btn-primary">Tampil</button>
</form>
<a href="{{ route('bookings.calendar',['bulan'=>$nextMonth]) }}" class="btn btn-outline-primary">›</a>
<a href="{{ route('bookings.calendar') }}" class="btn btn-ghost-secondary ms-auto">Bulan ini</a>
<span class="badge bg-blue-lt ms-2">{{ $bookings->count() }} booking</span>
</div></div>

<div class="card mt-2 no-print"><div class="table-responsive"><table class="table table-bordered card-table align-top mb-0">
<thead><tr class="text-center">
<th style="width:14.28%">Senin</th><th style="width:14.28%">Selasa</th><th style="width:14.28%">Rabu</th>
<th style="width:14.28%">Kamis</th><th style="width:14.28%">Jumat</th><th style="width:14.28%">Sabtu</th><th style="width:14.28%">Ahad</th>
</tr></thead><tbody>
@php
$firstDow = (int) $month->copy()->startOfMonth()->format('N'); // 1=Senin..7=Ahad
$daysInMonth = (int) $month->daysInMonth;
$day = 1;
$today = now()->format('Y-m-d');
@endphp
@for($week=0; $week<6; $week++)
@if($day > $daysInMonth) @break @endif
<tr>
@for($dow=1; $dow<=7; $dow++)
@php
$show = ($week===0) ? ($dow >= $firstDow) : ($day <= $daysInMonth);
$dateKey = $show && $day <= $daysInMonth ? $month->copy()->day($day)->format('Y-m-d') : null;
$items = $dateKey && isset($grouped[$dateKey]) ? $grouped[$dateKey] : [];
@endphp
<td style="min-width:140px;min-height:90px;vertical-align:top" class="{{ $dateKey===$today ? 'bg-yellow-lt' : '' }}">
@if($show && $day <= $daysInMonth)
<div class="fw-bold {{ $dateKey===$today ? 'text-warning' : '' }}">{{ $day }}</div>
@foreach($items as $b)
<div class="mt-1 p-1 border rounded small">
<span class="badge {{ match($b->status){'menunggu'=>'bg-yellow-lt','dikonfirmasi'=>'bg-blue-lt','dibatalkan'=>'bg-red-lt','selesai'=>'bg-green-lt',default=>'bg-secondary-lt'} }}">{{ $b->status }}</span>
<div class="fw-bold">{{ $b->code }}</div>
<div class="text-secondary">{{ $b->customer_name }} • {{ $b->device }}</div>
<div class="text-secondary">{{ $b->schedule_date ? \Carbon\Carbon::parse($b->schedule_date)->format('H:i') : \Carbon\Carbon::parse($b->created_at)->format('H:i').' (buat)' }}</div>
@if($b->status==='menunggu')
<form method="POST" action="{{ route('bookings.update',$b) }}" class="mt-1">@csrf @method('PUT')
<input type="hidden" name="status" value="dikonfirmasi">
<button class="btn btn-sm btn-primary w-100" onclick="return confirm('Konfirmasi {{ $b->code }}?')">Konfirmasi</button>
</form>
@endif
</div>
@endforeach
@php $day++; @endphp
@endif
</td>
@endfor
</tr>
@endfor
</tbody></table></div></div>
<p class="text-secondary small mt-2 no-print">
    Tanggal = <code>schedule_date</code>, fallback <code>created_at</code> bila jadwal kosong (ditandai “(buat)”).
</p>

@php
    $todayKey = now()->format('Y-m-d');
    $todayLabel = now()->locale('id')->isoFormat('dddd, D MMMM YYYY');
    $monthLabel = $month->locale('id')->isoFormat('MMMM YYYY');
    $todayItems = $grouped[$todayKey] ?? collect();
@endphp

{{-- Lembar cetak: tampilan bulan (ringkas, ramah printer) --}}
<div id="printBulan" class="print-sheet">
    <div class="print-head">
        <div>
            <div class="print-title">Kalender Booking — {{ $monthLabel }}</div>
            <div class="print-sub">
                Dicetak {{ now()->locale('id')->isoFormat('D MMM YYYY HH:mm') }}
                • {{ $bookings->count() }} booking
            </div>
        </div>
        <div class="print-sub text-end">
            Toko aktif
        </div>
    </div>
    <table class="print-table">
        <thead>
            <tr>
                <th>Senin</th>
                <th>Selasa</th>
                <th>Rabu</th>
                <th>Kamis</th>
                <th>Jumat</th>
                <th>Sabtu</th>
                <th>Ahad</th>
            </tr>
        </thead>
        <tbody>
            @php
                $pDay = 1;
            @endphp
            @for ($week = 0; $week < 6; $week++)
                @if ($pDay > $daysInMonth)
                    @break
                @endif
                <tr>
                    @for ($dow = 1; $dow <= 7; $dow++)
                        @php
                            $pShow = ($week === 0) ? ($dow >= $firstDow) : ($pDay <= $daysInMonth);
                            $pKey = ($pShow && $pDay <= $daysInMonth)
                                ? $month->copy()->day($pDay)->format('Y-m-d')
                                : null;
                            $pItems = ($pKey && isset($grouped[$pKey])) ? $grouped[$pKey] : [];
                        @endphp
                        <td class="print-cell">
                            @if ($pShow && $pDay <= $daysInMonth)
                                <div class="print-day">{{ $pDay }}</div>
                                @forelse ($pItems as $b)
                                    <div class="print-item">
                                        <strong>{{ $b->code }}</strong>
                                        [{{ $b->status }}]
                                        <br>
                                        {{ $b->customer_name }} • {{ $b->device }}
                                        <br>
                                        {{ $b->schedule_date ? \Carbon\Carbon::parse($b->schedule_date)->format('H:i') : \Carbon\Carbon::parse($b->created_at)->format('H:i') . ' (buat)' }}
                                    </div>
                                @empty
                                    <div class="print-empty">-</div>
                                @endforelse
                                @php $pDay++; @endphp
                            @endif
                        </td>
                    @endfor
                </tr>
            @endfor
        </tbody>
    </table>
    <div class="print-sign">
        <div>
            Petugas,
            <br><br><br>
            ( .................... )
        </div>
        <div>
            Dicetak: {{ now()->format('d/m/Y H:i') }}
        </div>
    </div>
</div>

{{-- Lembar cetak: daftar booking hari ini + checklist kedatangan manual --}}
<div id="printHarian" class="print-sheet">
    <div class="print-head">
        <div>
            <div class="print-title">Booking Hari Ini — {{ $todayLabel }}</div>
            <div class="print-sub">
                Dicetak {{ now()->locale('id')->isoFormat('D MMM YYYY HH:mm') }}
                • {{ count($todayItems) }} booking
            </div>
        </div>
        <div class="print-sub text-end">
            Checklist kedatangan manual (centang ☐ bila pelanggan hadir)
        </div>
    </div>
    <table class="print-table">
        <thead>
            <tr>
                <th style="width:36px">No</th>
                <th style="width:56px">Jam</th>
                <th>Kode / Pelanggan / Perangkat</th>
                <th style="width:90px">Status</th>
                <th style="width:64px">Hadir</th>
                <th style="width:120px">Tanda Tangan</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($todayItems as $i => $b)
                <tr>
                    <td class="text-center">{{ $i + 1 }}</td>
                    <td class="text-center">
                        {{ $b->schedule_date ? \Carbon\Carbon::parse($b->schedule_date)->format('H:i') : \Carbon\Carbon::parse($b->created_at)->format('H:i') }}
                    </td>
                    <td>
                        <strong>{{ $b->code }}</strong>
                        <br>
                        {{ $b->customer_name }}
                        {{ $b->customer_phone ? ' • ' . $b->customer_phone : '' }}
                        <br>
                        {{ $b->device ?? '-' }}
                        {{ $b->complaint ? ' — ' . \Illuminate\Support\Str::limit($b->complaint, 80) : '' }}
                    </td>
                    <td class="text-center">{{ $b->status }}</td>
                    <td class="text-center print-check">☐</td>
                    <td></td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center">
                        Tidak ada booking hari ini ({{ $todayKey }}).
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
    <div class="print-sign">
        <div>
            Kasir / Admin,
            <br><br><br>
            ( .................... )
        </div>
        <div>
            Teknisi,
            <br><br><br>
            ( .................... )
        </div>
    </div>
</div>

<style>
    .print-sheet {
        display: none;
    }
    .print-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 10px;
    }
    .print-title {
        font-size: 16pt;
        font-weight: 700;
    }
    .print-sub {
        font-size: 9pt;
        color: #333;
    }
    .print-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 9pt;
    }
    .print-table th,
    .print-table td {
        border: 1px solid #000;
        padding: 5px 6px;
        vertical-align: top;
    }
    .print-table thead th {
        background: #eee !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .print-cell {
        min-height: 70px;
    }
    .print-day {
        font-weight: 700;
        margin-bottom: 4px;
    }
    .print-item {
        margin-bottom: 5px;
        line-height: 1.3;
    }
    .print-empty {
        color: #888;
    }
    .print-check {
        font-size: 14pt;
    }
    .print-sign {
        display: flex;
        justify-content: space-between;
        margin-top: 22px;
        font-size: 10pt;
    }
    @media print {
        .no-print,
        .navbar,
        aside,
        footer {
            display: none !important;
        }
        body[data-print="printBulan"] #printBulan,
        body[data-print="printHarian"] #printHarian {
            display: block !important;
        }
        body[data-print="printBulan"] > *:not(#printBulan),
        body[data-print="printHarian"] > *:not(#printHarian) {
            visibility: hidden;
        }
        #printBulan,
        #printHarian {
            visibility: visible;
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
        }
        @page {
            size: A4 landscape;
            margin: 10mm;
        }
        body[data-print="printHarian"] {
            /* Lembar harian muat portrait juga; biarkan landscape agar kolom lega */
        }
    }
</style>

<script>
    function printArea(id) {
        document.body.dataset.print = id;
        window.print();
    }
    window.addEventListener('afterprint', function () {
        document.body.dataset.print = '';
    });
</script>
@endsection
