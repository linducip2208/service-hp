<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>
    Cetak Kartu Member Massal ({{ count($cards) }} kartu)
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
        max-width: 560px;
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
        background: #dee2e6;
        color: #343a40;
    }
    .badge.Silver { background: #dbe4ff; color: #1a4fd6; }
    .badge.Gold { background: #ffec99; color: #7a5b00; }
    .badge.Bronze { background: #e9dccf; color: #6b4a2b; }
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
    .barcode-box {
        margin-top: 12px;
        text-align: center;
        padding: 10px 8px 6px;
        border: 1px dashed #cbd5e1;
        border-radius: 10px;
        background: #fcfdff;
    }
    .barcode-box svg {
        max-width: 100%;
        height: auto;
    }
    .barcode-box .cap {
        font-size: 11px;
        color: #475467;
        margin-top: 2px;
        letter-spacing: .14em;
        font-weight: 700;
    }
    .card-foot {
        display: flex;
        gap: 14px;
        padding: 10px 18px 6px;
        align-items: center;
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
        height: 40px;
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
    <a href="{{ route('customers.index') }}">
        &larr; Kembali
    </a>
    <span class="info">
        <strong>{{ count($cards) }} kartu member</strong> siap dicetak
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
            $customer = $c['customer'];
            $tier = $c['tier'];
            $totalBelanja = $c['totalBelanja'];
            $salesCount = $c['salesCount'];
            $serviceCount = $c['serviceCount'];
            $memberSince = $c['memberSince'];
        @endphp
        <div class="card">
            <div class="card-head">
                <div class="logo">
                    {{ strtoupper(mb_substr($customer->store->name ?? 'S', 0, 1)) }}
                </div>
                <div>
                    <div class="store-name">
                        {{ $customer->store->name ?? config('app.name', 'ServiceHP') }}
                    </div>
                    <div class="store-meta">
                        {{ $customer->store->address ?? 'Kartu Member Pelanggan' }}
                        @if(!empty($customer->store->phone ?? null))
                            <br>
                            Telp/WA: {{ $customer->store->phone }}
                        @endif
                    </div>
                </div>
                <div class="no">
                    <div class="label">Kartu Member</div>
                    <div class="value">
                        {{ $customer->member_code }}
                    </div>
                    <div class="counter">
                        {{ $i + 1 }} / {{ count($cards) }}
                    </div>
                    <span class="badge {{ $tier }}">
                        {{ strtoupper($tier) }}
                    </span>
                </div>
            </div>

            <div class="card-body">
                <div class="grid">
                    <div class="field">
                        <div class="k">Nama Member</div>
                        <div class="v">
                            {{ $customer->name }}
                            <small>
                                {{ $customer->phone ?? '-' }}
                            </small>
                        </div>
                    </div>
                    <div class="field">
                        <div class="k">Kode Member</div>
                        <div class="v">
                            {{ $customer->member_code }}
                            <small>
                                Member sejak {{ $memberSince?->format('d/m/Y') ?? '-' }}
                            </small>
                        </div>
                    </div>
                    <div class="field">
                        <div class="k">Tier</div>
                        <div class="v">
                            {{ $tier }}
                            <small>
                                Bronze &lt;1jt • Silver 1–5jt • Gold &gt;5jt
                            </small>
                        </div>
                    </div>
                    <div class="field">
                        <div class="k">Total Belanja</div>
                        <div class="v">
                            Rp {{ number_format($totalBelanja, 0, ',', '.') }}
                            <small>
                                {{ $salesCount }} transaksi • {{ $serviceCount }} service
                            </small>
                        </div>
                    </div>
                </div>

                <div class="periode">
                    <span>Berlaku:</span>
                    <strong>Selama menjadi pelanggan aktif</strong>
                </div>

                <div class="barcode-box">
                    <svg
                        class="member-barcode"
                        data-code="{{ $customer->member_code }}"
                    ></svg>
                    <div class="cap">
                        {{ $customer->member_code }}
                    </div>
                </div>
            </div>

            <div class="card-foot">
                <div class="sign">
                    <div class="col">
                        <div>Pemegang kartu,</div>
                        <div class="box"></div>
                        <div class="line">
                            ( {{ $customer->name }} )
                        </div>
                    </div>
                    <div class="col">
                        <div>
                            {{ $customer->store->name ?? 'Toko' }},
                            {{ now()->format('d/m/Y') }}
                        </div>
                        <div class="box"></div>
                        <div class="line">
                            ( .................... )
                        </div>
                    </div>
                </div>
            </div>

            <div class="card-note">
                Tunjukkan kartu / barcode ini di kasir setiap transaksi agar poin &amp; tier tercatat.
                {{ $salesCount }} transaksi • {{ $serviceCount }} service • total Rp {{ number_format($totalBelanja, 0, ',', '.') }}.
            </div>
        </div>
    @endforeach
</div>

<script src="/vendor/jsbarcode/JsBarcode.all.min.js"></script>
<script>
    (function () {
        try {
            document.querySelectorAll('.member-barcode').forEach(function (el) {
                JsBarcode(el, el.getAttribute('data-code'), {
                    format: 'CODE128',
                    width: 2,
                    height: 64,
                    displayValue: false,
                    margin: 6
                });
            });
        } catch (e) {
            document.querySelectorAll('.member-barcode').forEach(function (el) {
                var fallback = document.createElement('div');
                fallback.style.fontWeight = '700';
                fallback.style.letterSpacing = '.2em';
                fallback.textContent = el.getAttribute('data-code');
                el.replaceWith(fallback);
            });
        }
    })();
</script>

</body>
</html>
