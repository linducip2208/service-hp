<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>
    Lembar QR Semua Promo Aktif ({{ $promos->count() }} promo)
</title>
<style>
    * {
        box-sizing: border-box;
    }
    body {
        margin: 0;
        font-family: "Segoe UI", Arial, sans-serif;
        background: #e9edf3;
        color: #1c2333;
    }
    .toolbar {
        max-width: 900px;
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
        max-width: 900px;
        margin: 12px auto 32px;
        padding: 0 16px;
    }
    .page {
        background: #fff;
        border: 1px solid #cbd5e1;
        border-radius: 14px;
        padding: 20px;
        margin-bottom: 20px;
    }
    .page-head {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        border-bottom: 2px solid #0f2a4a;
        padding-bottom: 8px;
        margin-bottom: 14px;
    }
    .page-head h2 {
        margin: 0;
        font-size: 16px;
    }
    .page-head span {
        font-size: 12px;
        color: #667085;
    }
    .qr-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 14px;
    }
    .qr-card {
        border: 1.5px dashed #98a2b3;
        border-radius: 12px;
        padding: 12px 10px;
        text-align: center;
        page-break-inside: avoid;
    }
    .qr-card .qr-box {
        width: 120px;
        height: 120px;
        margin: 0 auto 8px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .qr-card .qr-box img,
    .qr-card .qr-box canvas {
        max-width: 120px;
        max-height: 120px;
    }
    .qr-card .kode {
        font-family: monospace;
        font-size: 17px;
        font-weight: 800;
        letter-spacing: 0.12em;
        word-break: break-all;
    }
    .qr-card .nama {
        font-size: 12.5px;
        font-weight: 600;
        margin-top: 2px;
    }
    .qr-card .syarat {
        font-size: 11.5px;
        color: #344054;
        margin-top: 4px;
        line-height: 1.5;
    }
    .qr-card .periode {
        margin-top: 6px;
        display: inline-block;
        font-size: 11px;
        background: #f1f7ff;
        border: 1px dashed #84aef2;
        border-radius: 999px;
        padding: 2px 10px;
    }
    .empty {
        text-align: center;
        padding: 40px 16px;
        color: #667085;
    }
    @media print {
        body {
            background: #fff;
        }
        .toolbar {
            display: none !important;
        }
        .sheet {
            max-width: none;
            margin: 0;
            padding: 0;
        }
        .page {
            border: none;
            border-radius: 0;
            margin: 0;
            padding: 0 0 8px;
            page-break-after: always;
        }
        .page:last-child {
            page-break-after: auto;
        }
        .qr-card {
            border: 1.5px solid #000;
        }
        @page {
            size: A4;
            margin: 10mm;
        }
    }
</style>
</head>
<body>

<div class="toolbar">
    <a href="{{ route('promos.index') }}">
        &larr; Kembali
    </a>
    <span class="info">
        <strong>{{ $promos->count() }} promo aktif</strong>
        per {{ \Carbon\Carbon::parse($today)->format('d/m/Y') }}
    </span>
    <button
        type="button"
        class="primary"
        onclick="window.print()"
    >
        Cetak Semua ({{ $promos->count() }})
    </button>
</div>

<div class="sheet">
    @if ($promos->isEmpty())
        <div class="page">
            <div class="empty">
                <p><strong>Tidak ada promo aktif.</strong></p>
                <p>Aktifkan promo di halaman index terlebih dahulu.</p>
            </div>
        </div>
    @else
        @foreach ($promos->chunk(6) as $pageNo => $chunk)
            <div class="page">
                <div class="page-head">
                    <h2>
                        QR Promo Aktif
                    </h2>
                    <span>
                        Hal. {{ $pageNo + 1 }} / {{ (int) ceil($promos->count() / 6) }}
                    </span>
                </div>
                <div class="qr-grid">
                    @foreach ($chunk as $p)
                        <div class="qr-card">
                            <div
                                class="qr-box"
                                data-code="{{ $p->code }}"
                            ></div>
                            <div class="kode">
                                {{ $p->code }}
                            </div>
                            <div class="nama">
                                {{ $p->name }}
                            </div>
                            <div class="syarat">
                                @if ($p->type === 'diskon_persen')
                                    Diskon {{ rtrim(rtrim(number_format($p->value, 2, ',', '.'), '0'), ',') }}%
                                @elseif ($p->type === 'diskon_rupiah')
                                    Potongan Rp {{ number_format($p->value, 0, ',', '.') }}
                                @elseif ($p->type === 'bundling')
                                    Bundling {{ rtrim(rtrim(number_format($p->value, 2, ',', '.'), '0'), ',') }}%
                                @elseif ($p->type === 'voucher')
                                    Voucher Rp {{ number_format($p->value, 0, ',', '.') }}
                                @else
                                    {{ ucfirst($p->type) }}: {{ number_format($p->value, 0, ',', '.') }}
                                @endif
                                <br>
                                Min. belanja Rp {{ number_format($p->min_belanja, 0, ',', '.') }}
                                @if ($p->quota > 0)
                                    <br>
                                    Quota {{ $p->used }}/{{ $p->quota }}
                                @endif
                                @if ($p->store)
                                    <br>
                                    {{ $p->store->name }}
                                @endif
                            </div>
                            <div class="periode">
                                {{ $p->start_date?->format('d/m/Y') ?? '–' }}
                                s/d
                                {{ $p->end_date?->format('d/m/Y') ?? '–' }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    @endif
</div>

<script src="/vendor/qrcode/qrcode.min.js"></script>
<script>
(function () {
    // Pola yang sama dengan promos/show: payload = kode promo mentah,
    // teks kode tetap tampil sebagai fallback bila CDN gagal dimuat.
    var boxes = document.querySelectorAll('.qr-box');
    boxes.forEach(function (box) {
        var kode = box.getAttribute('data-code') || '';
        try {
            if (typeof QRCode !== 'undefined') {
                new QRCode(box, {
                    text: String(kode),
                    width: 120,
                    height: 120,
                    correctLevel: QRCode.CorrectLevel.M
                });
            }
        } catch (e) {
            box.innerHTML = '';
        }
    });
})();
</script>

</body>
</html>
