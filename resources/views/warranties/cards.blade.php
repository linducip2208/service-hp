<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>
    Cetak Kartu Garansi Massal ({{ count($cards) }} kartu)
</title>
<style>
    * { box-sizing: border-box; }
    body {
        margin: 0;
        font-family: "Segoe UI", Arial, sans-serif;
        background: #e9edf3;
        color: #1c2333;
    }
    .toolbar {
        max-width: 720px;
        margin: 16px auto 0;
        padding: 0 16px;
        display: flex;
        gap: 8px;
        align-items: center;
        justify-content: space-between;
    }
    .toolbar .info {
        font-size: 14px;
    }
    .toolbar a,
    .toolbar button {
        display: inline-block;
        padding: 8px 16px;
        border-radius: 8px;
        font-size: 14px;
        cursor: pointer;
        text-decoration: none;
        border: 1px solid #cbd5e1;
        background: #fff;
        color: #1c2333;
    }
    .toolbar button.primary {
        background: #206bc4;
        border-color: #206bc4;
        color: #fff;
        font-weight: 600;
    }
    .sheet {
        max-width: 720px;
        margin: 12px auto 32px;
        padding: 0 16px;
    }
    .card {
        width: 100%;
        max-width: 640px;
        margin: 0 auto 20px;
        background: #fff;
        border: 1px solid #cbd5e1;
        border-radius: 14px;
        overflow: hidden;
    }
    .card-head {
        display: flex;
        gap: 12px;
        align-items: center;
        padding: 14px 18px;
        background: #0f2a4a;
        color: #fff;
    }
    .card-head .logo {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        background: #f59f00;
        color: #0f2a4a;
        font-weight: 800;
        font-size: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .card-head .store-name {
        font-size: 17px;
        font-weight: 700;
        line-height: 1.2;
    }
    .card-head .store-meta {
        font-size: 11.5px;
        opacity: .85;
        line-height: 1.45;
    }
    .card-head .no {
        margin-left: auto;
        text-align: right;
        flex-shrink: 0;
    }
    .card-head .no .label {
        font-size: 10px;
        letter-spacing: .12em;
        text-transform: uppercase;
        opacity: .8;
    }
    .card-head .no .value {
        font-size: 15px;
        font-weight: 700;
    }
    .card-head .counter {
        font-size: 10.5px;
        opacity: .75;
        margin-top: 2px;
    }
    .badge {
        display: inline-block;
        margin-top: 4px;
        padding: 2px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        background: #d3f9d8;
        color: #0c6b3c;
    }
    .badge.klaim { background: #ffec99; color: #7a5b00; }
    .badge.selesai { background: #dee2e6; color: #343a40; }
    .badge.expired { background: #ffc9c9; color: #a61e1e; }
    .card-body {
        padding: 14px 18px 10px;
    }
    .grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px 16px;
    }
    .field .k {
        font-size: 10.5px;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: #667085;
        margin-bottom: 2px;
    }
    .field .v {
        font-size: 13.5px;
        font-weight: 600;
        line-height: 1.45;
    }
    .field .v small {
        display: block;
        font-weight: 400;
        color: #475467;
        font-size: 12px;
    }
    .periode {
        margin-top: 10px;
        background: #f1f7ff;
        border: 1px dashed #84aef2;
        border-radius: 10px;
        padding: 8px 12px;
        font-size: 13px;
        display: flex;
        gap: 10px;
        align-items: center;
        justify-content: space-between;
    }
    .periode strong { font-size: 14px; }
    .terms {
        margin: 10px 0 0;
        padding-left: 18px;
        font-size: 11.5px;
        color: #344054;
        line-height: 1.6;
    }
    .card-foot {
        display: flex;
        gap: 14px;
        padding: 10px 18px 6px;
        align-items: center;
    }
    .qr {
        text-align: center;
        flex-shrink: 0;
    }
    .qr img {
        width: 104px;
        height: 104px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        background: #fff;
    }
    .qr .cap {
        font-size: 10.5px;
        color: #475467;
        margin-top: 4px;
        max-width: 120px;
        word-break: break-all;
    }
    .sign {
        display: flex;
        gap: 12px;
        flex: 1;
        margin-top: 6px;
    }
    .sign .col {
        flex: 1;
        text-align: center;
        font-size: 11.5px;
        color: #475467;
    }
    .sign .box {
        height: 52px;
    }
    .sign .line {
        border-top: 1px solid #98a2b3;
        padding-top: 4px;
    }
    .card-note {
        padding: 8px 18px 14px;
        font-size: 10.5px;
        color: #667085;
        border-top: 1px dashed #e4e7ec;
        margin-top: 8px;
    }
    @media print {
        body { background: #fff; }
        .toolbar { display: none !important; }
        .sheet {
            max-width: none;
            margin: 0;
            padding: 0;
        }
        .card {
            max-width: none;
            border-radius: 0;
            border: 1.5px solid #000;
            margin: 0;
            page-break-after: always;
        }
        .card:last-child {
            page-break-after: auto;
        }
        @page { size: A6 landscape; margin: 6mm; }
    }
</style>
</head>
<body>

<div class="toolbar">
    <a href="{{ route('warranties.index') }}">
        &larr; Kembali
    </a>
    <span class="info">
        <strong>{{ count($cards) }} kartu garansi</strong> siap dicetak
    </span>
    <button
        type="button"
        class="primary"
        onclick="window.print()"
    >
        🖨 Cetak Semua ({{ count($cards) }})
    </button>
</div>

<div class="sheet">
    @foreach($cards as $i => $c)
        @php
            $warranty = $c['warranty'];
            $ticket = $c['ticket'];
            $store = $c['store'];
            $isExpired = $c['isExpired'];
        @endphp
        <div class="card">
            <div class="card-head">
                <div class="logo">
                    {{ strtoupper(mb_substr($store->name ?? 'S', 0, 1)) }}
                </div>
                <div>
                    <div class="store-name">
                        {{ $store->name ?? 'Service HP' }}
                    </div>
                    <div class="store-meta">
                        {{ $store->address ?? 'Alamat toko' }}
                        @if(!empty($store->phone))
                            <br>
                            Telp/WA: {{ $store->phone }}
                        @endif
                    </div>
                </div>
                <div class="no">
                    <div class="label">Kartu Garansi</div>
                    <div class="value">
                        #{{ $warranty->id }}
                    </div>
                    <div class="counter">
                        {{ $i + 1 }} / {{ count($cards) }}
                    </div>
                    <span class="badge {{ $warranty->status === 'klaim' ? 'klaim' : ($warranty->status === 'selesai' ? 'selesai' : '') }}">
                        {{ strtoupper($warranty->status) }}
                    </span>
                    @if($isExpired)
                        <br>
                        <span class="badge expired">
                            KEDALUWARSA
                        </span>
                    @endif
                </div>
            </div>

            <div class="card-body">
                <div class="grid">
                    <div class="field">
                        <div class="k">Nama Pelanggan</div>
                        <div class="v">
                            {{ $warranty->customer->name ?? '-' }}
                            <small>
                                {{ $warranty->customer->phone ?? '' }}
                            </small>
                        </div>
                    </div>
                    <div class="field">
                        <div class="k">Produk / Perangkat</div>
                        <div class="v">
                            @if($warranty->productItem && $warranty->productItem->product)
                                {{ $warranty->productItem->product->name ?? $warranty->productItem->product->brand ?? 'Produk' }}
                                <small>
                                    IMEI/SN: {{ $warranty->productItem->imei ?? $warranty->productItem->serial ?? '-' }}
                                </small>
                            @elseif($ticket)
                                {{ trim(($ticket->product_brand ?? '') . ' ' . ($ticket->product_type ?? '')) ?: 'Service' }}
                                <small>
                                    Tiket {{ $ticket->ticket_no }}
                                    @if($ticket->imei)
                                        • IMEI: {{ $ticket->imei }}
                                    @endif
                                </small>
                            @else
                                {{ $warranty->type ?? 'Garansi service' }}
                                <small>
                                    Ref: {{ $warranty->ref_type ?? '-' }} #{{ $warranty->ref_id ?? '-' }}
                                </small>
                            @endif
                        </div>
                    </div>
                    <div class="field">
                        <div class="k">Referensi</div>
                        <div class="v">
                            @if($ticket)
                                Tiket {{ $ticket->ticket_no }} (#{{ $ticket->id }})
                                <small>
                                    {{ $ticket->complaint ? \Illuminate\Support\Str::limit($ticket->complaint, 60) : '' }}
                                </small>
                            @else
                                {{ $warranty->ref_type ?? '-' }} #{{ $warranty->ref_id ?? '-' }}
                                <small>{{ $warranty->type ?? '' }}</small>
                            @endif
                        </div>
                    </div>
                    <div class="field">
                        <div class="k">Jenis Garansi</div>
                        <div class="v">
                            {{ $warranty->type ?? 'Service' }}
                            @if($warranty->claim_note)
                                <small>
                                    Catatan: {{ \Illuminate\Support\Str::limit($warranty->claim_note, 70) }}
                                </small>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="periode">
                    <span>Berlaku:</span>
                    <strong>
                        {{ $warranty->start_date?->format('d/m/Y') ?? '-' }}
                        &rarr;
                        {{ $warranty->end_date?->format('d/m/Y') ?? '-' }}
                    </strong>
                </div>

                <ol class="terms">
                    <li>
                        Wajib tunjukkan kartu ini / sebutkan ID garansi saat klaim.
                    </li>
                    <li>
                        Garansi hangus bila segel rusak, kena air, jatuh, atau diservis pihak lain.
                    </li>
                    <li>
                        Klaim hanya untuk kerusakan yang sama pada periode berlaku.
                    </li>
                </ol>
            </div>

            <div class="card-foot">
                <div class="qr">
                    <img src="{{ $qrUrl }}" alt="QR lacak service">
                    <div class="cap">
                        Pindai untuk lacak
                        <br>
                        {{ $trackUrl }}
                    </div>
                </div>
                <div class="sign">
                    <div class="col">
                        <div>Pelanggan,</div>
                        <div class="box"></div>
                        <div class="line">( .................... )</div>
                    </div>
                    <div class="col">
                        <div>
                            {{ $store->name ?? 'Toko' }},
                            {{ now()->format('d/m/Y') }}
                        </div>
                        <div class="box"></div>
                        <div class="line">( .................... )</div>
                    </div>
                </div>
            </div>

            <div class="card-note">
                Simpan kartu ini selama masa garansi. Cek status via QR / halaman Lacak Service dengan nomor tiket
                {{ $ticket->ticket_no ?? ('#' . ($warranty->ref_id ?? $warranty->id)) }}.
            </div>
        </div>
    @endforeach
</div>

</body>
</html>
