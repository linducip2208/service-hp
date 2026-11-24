<!doctype html>
<html
    lang="id"
>
<head>
<meta
    charset="utf-8"
>
<title>
Slip Komisi Massal ({{ count($slips) }} teknisi) — {{ $bulan }}
</title>
<link
    href="/vendor/tabler/tabler.min.css"
    rel="stylesheet"
>
<style>
.slip-block {
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 18px;
    margin-bottom: 18px;
}
.letter-box {
    max-width: 210mm;
    margin: auto;
    background: #fff;
    color: #000;
}
@media print {
    .no-print {
        display: none !important;
    }
    body {
        background: #fff;
    }
    .letter-box {
        max-width: 100%;
        box-shadow: none !important;
        margin: 0;
    }
    .slip-block {
        border: none;
        border-radius: 0;
        padding: 0;
        margin-bottom: 0;
        page-break-after: always;
    }
    .slip-block:last-child {
        page-break-after: auto;
    }
    @page {
        size: A4;
        margin: 12mm;
    }
}
</style>
</head>
<body
    class="bg-light"
>
<div
    class="letter-box p-4 shadow-sm my-3"
>
<div
    class="no-print alert alert-info d-flex justify-content-between align-items-center"
>
<div>
<b>
{{ count($slips) }} slip komisi — periode {{ $bulan }} ({{ $start }} s/d {{ $end }})
</b>
<span
    class="text-secondary"
>
— total Rp {{ number_format(collect($slips)->sum('total'), 0, ',', '.') }}
</span>
</div>
<div
    class="d-flex gap-2"
>
<button
    class="btn btn-primary btn-sm"
    onclick="window.print()"
>
🖨️ Cetak Semua
</button>
<a
    class="btn btn-outline-secondary btn-sm"
    href="{{ route('technicians.report', ['bulan' => $bulan]) }}"
>
Laporan
</a>
<a
    class="btn btn-outline-secondary btn-sm"
    href="{{ route('technicians.index') }}"
>
Teknisi
</a>
</div>
</div>

@foreach($slips as $idx => $slip)
@php
$tech = $slip['tech'];
$rows = $slip['rows'];
$menunggu = $slip['menunggu'];
$dibayar = $slip['dibayar'];
$total = $slip['total'];
@endphp
<section
    class="slip-block"
>
<div
    class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3"
>
<div>
<h2
    class="mb-0"
>
{{ $store->name ?? 'ServiceHP' }}
</h2>
<div
    class="text-secondary"
>
{{ $store->address ?? '' }}
@if(!empty($store->phone))
<br>
{{ $store->phone }}
@endif
</div>
</div>
<div
    class="text-end"
>
<h1
    class="mb-0"
>
SLIP KOMISI
</h1>
<b>
No. KOM-{{ $tech->id }}-{{ str_replace('-', '', $bulan) }}
</b>
<br>
<span
    class="text-secondary"
>
Periode {{ $bulan }} ({{ $start }} s/d {{ $end }})
</span>
<br>
<span
    class="badge bg-blue-lt"
>
{{ $idx + 1 }} / {{ count($slips) }}
</span>
</div>
</div>

<table
    class="table table-bordered"
>
<tbody>
<tr>
<th
    style="width:35%"
>
Teknisi
</th>
<td>
{{ $tech->name }}
@if(!empty($tech->phone))
<span
    class="text-secondary"
>
· {{ $tech->phone }}
</span>
@endif
</td>
</tr>
<tr>
<th>
Periode
</th>
<td>
{{ $start }} s/d {{ $end }}
</td>
</tr>
<tr>
<th>
Jumlah komisi
</th>
<td>
{{ $rows->count() }} baris
</td>
</tr>
<tr>
<th>
Dicetak
</th>
<td>
{{ now()->format('d/m/Y H:i') }} oleh {{ auth()->user()->name ?? 'Admin' }}
</td>
</tr>
</tbody>
</table>

<h3
    class="mt-4"
>
Daftar Komisi
</h3>
@if($rows->count())
<div
    class="table-responsive"
>
<table
    class="table table-bordered"
>
<thead>
<tr>
<th
    style="width:40px"
>
#
</th>
<th>
Tanggal
</th>
<th>
Tiket
</th>
<th
    class="text-end"
>
Nominal
</th>
<th>
Status
</th>
</tr>
</thead>
<tbody>
@foreach($rows as $i => $c)
<tr>
<td>
{{ $i + 1 }}
</td>
<td>
{{ $c->created_at ? $c->created_at->format('d/m/Y') : '-' }}
</td>
<td>
{{ $c->ticket->ticket_no ?? ('#' . $c->service_ticket_id) }}
</td>
<td
    class="text-end"
>
Rp {{ number_format($c->amount, 0, ',', '.') }}
</td>
<td>
{{ $c->status }}
</td>
</tr>
@endforeach
</tbody>
<tfoot>
<tr
    class="table-light"
>
<td
    colspan="3"
    class="text-end"
>
<b>
Menunggu
</b>
</td>
<td
    class="text-end"
>
<b>
Rp {{ number_format($menunggu, 0, ',', '.') }}
</b>
</td>
<td></td>
</tr>
<tr
    class="table-light"
>
<td
    colspan="3"
    class="text-end"
>
<b>
Sudah dibayar
</b>
</td>
<td
    class="text-end"
>
<b>
Rp {{ number_format($dibayar, 0, ',', '.') }}
</b>
</td>
<td></td>
</tr>
<tr>
<td
    colspan="3"
    class="text-end"
>
<b>
TOTAL KOMISI
</b>
</td>
<td
    class="text-end"
>
<b>
Rp {{ number_format($total, 0, ',', '.') }}
</b>
</td>
<td></td>
</tr>
</tfoot>
</table>
</div>
@else
<p
    class="text-secondary"
>
Belum ada komisi untuk teknisi ini pada periode {{ $bulan }}.
</p>
@endif

<div
    class="row mt-5"
>
<div
    class="col-6 text-center text-secondary"
>
Teknisi penerima,
<br>
<br>
<br>
<br>
___________________
<br>
<b>
{{ $tech->name }}
</b>
</div>
<div
    class="col-6 text-center"
>
Hormat kami,
<br>
{{ $store->name ?? 'ServiceHP' }}
<br>
<br>
<br>
___________________
<br>
<b>
{{ auth()->user()->name ?? 'Admin' }}
</b>
</div>
</div>
</section>
@endforeach

<div
    class="text-center no-print mt-2 d-flex gap-2 justify-content-center"
>
<button
    class="btn btn-primary"
    onclick="window.print()"
>
🖨️ Cetak Semua ({{ count($slips) }})
</button>
<a
    class="btn btn-outline-secondary"
    href="{{ route('technicians.index') }}"
>
Kembali
</a>
</div>
</div>
</body>
</html>
