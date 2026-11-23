<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>
    Cetak PO Massal ({{ $purchases->count() }} PO) — {{ $store->name ?? 'ServiceHP' }}
</title>
<link
    href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css"
    rel="stylesheet"
>
<style>
    .po-box {
        max-width: 210mm;
        margin: auto;
        background: #fff;
        color: #000;
    }
    .po-block {
        border: 1px solid #dee2e6;
        border-radius: 6px;
        padding: 18px;
        margin-bottom: 18px;
    }
    @media print {
        .no-print {
            display: none !important;
        }
        body {
            background: #fff;
        }
        .po-box {
            max-width: 100%;
            margin: 0;
        }
        .po-block {
            border: none;
            border-radius: 0;
            padding: 0;
            margin-bottom: 0;
            page-break-after: always;
        }
        .po-block:last-child {
            page-break-after: auto;
        }
        @page {
            size: A4;
            margin: 12mm;
        }
    }
</style>
</head>
<body class="bg-light">
<div class="po-box p-4 shadow-sm my-3">
    <div class="no-print alert alert-info d-flex justify-content-between align-items-center">
        <div>
            <b>
                {{ $purchases->count() }} PO terpilih
            </b>
            <span class="text-secondary">
                — total Rp {{ number_format($purchases->sum('total'), 0, ',', '.') }}
            </span>
        </div>
        <div class="d-flex gap-2">
            <button
                class="btn btn-primary btn-sm"
                onclick="window.print()"
            >
                <i class="ti ti-printer me-1"></i> Cetak Semua
            </button>
            <a
                class="btn btn-outline-secondary btn-sm"
                href="{{ route('purchases.index') }}"
            >
                Kembali
            </a>
        </div>
    </div>

    @foreach($purchases as $idx => $po)
        @php
            $sisa = (float) $po->total - (float) $po->paid;
            $notaBersih = trim(
                preg_replace(
                    '/\s*\[(BUKTI|FOTO|DITERIMA)[^\]]*\]/',
                    '',
                    (string) $po->note
                )
            );
        @endphp
        <section class="po-block">
            <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3">
                <div>
                    <h2 class="mb-0">
                        {{ $store->name ?? 'ServiceHP' }}
                    </h2>
                    <div class="text-secondary">
                        {{ $store->address ?? '' }}
                        @if(!empty($store->phone))
                            <br>
                            {{ $store->phone }}
                        @endif
                    </div>
                </div>
                <div class="text-end">
                    <h1 class="mb-0">
                        PURCHASE ORDER
                    </h1>
                    <b>
                        {{ $po->invoice_no }}
                    </b>
                    <br>
                    <span class="text-secondary">
                        {{ $po->created_at ? $po->created_at->format('d/m/Y H:i') : '-' }}
                    </span>
                    <br>
                    <span class="badge bg-blue-lt">
                        {{ $idx + 1 }} / {{ $purchases->count() }}
                    </span>
                    <span class="badge bg-{{ $po->status === 'lunas' ? 'green' : ($po->status === 'belum' ? 'red' : 'yellow') }}-lt ms-1">
                        {{ $po->status }}
                    </span>
                </div>
            </div>

            <table class="table table-bordered">
                <tbody>
                    <tr>
                        <th style="width:35%">
                            Supplier
                        </th>
                        <td>
                            <b>
                                {{ $po->supplier->name ?? '-' }}
                            </b>
                            @if(!empty($po->supplier->phone))
                                <span class="text-secondary">
                                    ({{ $po->supplier->phone }})
                                </span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <th>
                            Total
                        </th>
                        <td class="text-end">
                            Rp {{ number_format($po->total, 0, ',', '.') }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            Dibayar
                        </th>
                        <td class="text-end">
                            Rp {{ number_format($po->paid, 0, ',', '.') }}
                        </td>
                    </tr>
                    <tr>
                        <th>
                            Sisa
                        </th>
                        <td class="text-end">
                            <b>
                                Rp {{ number_format($sisa, 0, ',', '.') }}
                            </b>
                        </td>
                    </tr>
                    <tr>
                        <th>
                            Status
                        </th>
                        <td>
                            <span class="badge bg-{{ $po->status === 'lunas' ? 'green' : ($po->status === 'belum' ? 'red' : 'yellow') }}-lt">
                                {{ $po->status }}
                            </span>
                        </td>
                    </tr>
                    @if($notaBersih)
                        <tr>
                            <th>
                                Catatan
                            </th>
                            <td>
                                {{ $notaBersih }}
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>

            <h3 class="mt-4">
                Item Barang
            </h3>
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>
                                #
                            </th>
                            <th>
                                Produk
                            </th>
                            <th class="text-end">
                                Qty
                            </th>
                            <th class="text-end">
                                Harga
                            </th>
                            <th class="text-end">
                                Subtotal
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($po->items as $i => $it)
                            <tr>
                                <td>
                                    {{ $i + 1 }}
                                </td>
                                <td>
                                    {{ $it->product->name ?? 'Produk #' . $it->product_id }}
                                </td>
                                <td class="text-end">
                                    {{ $it->qty }}
                                </td>
                                <td class="text-end">
                                    {{ number_format($it->price, 0, ',', '.') }}
                                </td>
                                <td class="text-end">
                                    {{ number_format($it->subtotal, 0, ',', '.') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="table-light">
                            <td
                                colspan="4"
                                class="text-end"
                            >
                                <b>
                                    Total PO
                                </b>
                            </td>
                            <td class="text-end">
                                <b>
                                    Rp {{ number_format($po->total, 0, ',', '.') }}
                                </b>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="row mt-5">
                <div class="col-6 text-center text-secondary">
                    Supplier,
                    <br>
                    <br>
                    <br>
                    <br>
                    ___________________
                    <br>
                    <b>
                        {{ $po->supplier->name ?? '-' }}
                    </b>
                </div>
                <div class="col-6 text-center">
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

    <div class="text-center no-print mt-2 d-flex gap-2 justify-content-center">
        <button
            class="btn btn-primary"
            onclick="window.print()"
        >
            <i class="ti ti-printer me-1"></i> Cetak Semua ({{ $purchases->count() }})
        </button>
        <a
            class="btn btn-outline-secondary"
            href="{{ route('purchases.index') }}"
        >
            Kembali
        </a>
    </div>
</div>
</body>
</html>
