<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Surat Jalan {{ $batch }} — ServiceHP</title>
    <link href="/vendor/tabler/tabler.min.css" rel="stylesheet">
    <style>
        .sheet {
            max-width: 800px;
            margin: auto;
            background: #fff;
            color: #000;
        }
        .sign-box {
            height: 90px;
        }
        @media print {
            body * {
                visibility: hidden !important;
            }
            #surat-area,
            #surat-area * {
                visibility: visible !important;
            }
            #surat-area {
                position: absolute;
                left: 0;
                top: 0;
                width: 100% !important;
                max-width: 100%;
                margin: 0;
            }
            @page {
                margin: 12mm;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body class="bg-light">
<div class="container py-3">
    <div class="card mb-3 no-print">
        <div class="card-header">
            <h3 class="card-title">Surat jalan mutasi</h3>
            <span class="card-subtitle ms-2 font-monospace">{{ $batch }}</span>
        </div>
        <div class="card-body">
            <div class="btn-list">
                <button class="btn btn-primary btn-sm" onclick="window.print()">Cetak / Simpan PDF</button>
                <a class="btn btn-outline-dark btn-sm" href="{{ route('stocks.mutations') }}">Kembali ke mutasi</a>
            </div>
        </div>
    </div>

    <div id="surat-area" class="sheet p-4 shadow-sm rounded">
        <h2 class="text-center mb-0">SURAT JALAN MUTASI STOK</h2>
        <p class="text-center text-secondary mb-3">ServiceHP — transfer antar toko</p>
        <table class="table table-sm">
            <tr>
                <th style="width:180px">No. Surat / Batch</th>
                <td class="font-monospace">
                    <strong>{{ $batch }}</strong>
                </td>
            </tr>
            <tr>
                <th>Tanggal kirim</th>
                <td>{{ $tanggal ? $tanggal->format('d/m/Y H:i') : '-' }}</td>
            </tr>
            <tr>
                <th>Toko asal</th>
                <td>{{ $fromStore->name ?? '?' }}</td>
            </tr>
            <tr>
                <th>Toko tujuan</th>
                <td>{{ $toStore->name ?? '?' }}</td>
            </tr>
            <tr>
                <th>Dibuat oleh</th>
                <td>{{ $dibuatOleh }}</td>
            </tr>
            @if($note !== '')
                <tr>
                    <th>Catatan</th>
                    <td>{{ $note }}</td>
                </tr>
            @endif
        </table>

        <h4 class="mt-3">A. Produk non-serial</h4>
        <div class="table-responsive">
            <table class="table table-bordered table-sm">
                <thead>
                    <tr>
                        <th style="width:40px">No</th>
                        <th>SKU</th>
                        <th>Nama produk</th>
                        <th class="text-end" style="width:90px">Qty</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $i => $it)
                        <tr>
                            <td>{{ $i + 1 }}</td>
                            <td class="font-monospace">{{ $it['sku'] }}</td>
                            <td>{{ $it['name'] }}</td>
                            <td class="text-end">{{ $it['qty'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-secondary">Tidak ada produk non-serial.</td>
                        </tr>
                    @endforelse
                </tbody>
                @if($items->isNotEmpty())
                    <tfoot>
                        <tr>
                            <th colspan="3" class="text-end">Total</th>
                            <th class="text-end">{{ $totalQty }} pcs</th>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        <h4 class="mt-3">B. Unit serial / IMEI</h4>
        <div class="table-responsive">
            <table class="table table-bordered table-sm">
                <thead>
                    <tr>
                        <th style="width:40px">No</th>
                        <th>IMEI / SN</th>
                        <th>Produk</th>
                        <th>SKU</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($units as $i => $u)
                        <tr>
                            <td>{{ $i + 1 }}</td>
                            <td class="font-monospace">{{ $u['imei'] }}</td>
                            <td>{{ $u['product'] }}</td>
                            <td class="font-monospace">{{ $u['sku'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-secondary">Tidak ada unit IMEI.</td>
                        </tr>
                    @endforelse
                </tbody>
                @if($units->isNotEmpty())
                    <tfoot>
                        <tr>
                            <th colspan="4">Total {{ $units->count() }} unit</th>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        <p class="small text-secondary mt-2">
            Barang di atas dikirim dalam keadaan baik. Mohon diperiksa sebelum ditandatangani.
        </p>

        <div class="row mt-4 text-center">
            <div class="col-4">
                <div>Pengirim</div>
                <div class="sign-box"></div>
                <div>(....................)</div>
                <div class="small text-secondary">Nama &amp; tgl</div>
            </div>
            <div class="col-4">
                <div>Supir / Kurir</div>
                <div class="sign-box"></div>
                <div>(....................)</div>
                <div class="small text-secondary">Nama &amp; tgl</div>
            </div>
            <div class="col-4">
                <div>Penerima</div>
                <div class="sign-box"></div>
                <div>(....................)</div>
                <div class="small text-secondary">Nama &amp; tgl</div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
