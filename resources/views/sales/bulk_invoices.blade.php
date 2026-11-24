<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>
  Cetak Invoice Massal ({{ $sales->count() }} invoice) — {{ $store->name ?? 'ServiceHP' }}
</title>
<link href="/vendor/tabler/tabler.min.css" rel="stylesheet">
<style>
  .letter-box {
    max-width: 210mm;
    margin: auto;
    background: #fff;
    color: #000;
  }
  .bill-block {
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
    .letter-box {
      max-width: 100%;
      margin: 0;
    }
    .bill-block {
      border: none;
      border-radius: 0;
      padding: 0;
      margin-bottom: 0;
      page-break-after: always;
    }
    .bill-block:last-child {
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
<div class="letter-box p-4 shadow-sm my-3">
  <div class="no-print alert alert-info d-flex justify-content-between align-items-center">
    <div>
      <b>
        {{ $sales->count() }} invoice terpilih
      </b>
      <span class="text-secondary">
        — total Rp {{ number_format($sales->sum('total'), 0, ',', '.') }}
      </span>
    </div>
    <div class="d-flex gap-2">
      <button
        class="btn btn-primary btn-sm"
        onclick="window.print()"
      >
        🖨️ Cetak Semua
      </button>
      <a
        class="btn btn-outline-secondary btn-sm"
        href="{{ route('sales.index') }}"
      >
        Kembali
      </a>
    </div>
  </div>

  @foreach($sales as $idx => $sale)
    <section class="bill-block">
      <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3">
        <div>
          <h2 class="mb-0">
            {{ $sale->store->name ?? ($store->name ?? 'ServiceHP') }}
          </h2>
          <div class="text-secondary">
            {{ $sale->store->address ?? ($store->address ?? '') }}
            @if(!empty($sale->store->phone ?? $store->phone ?? ''))
              <br>
              {{ $sale->store->phone ?? $store->phone }}
            @endif
          </div>
        </div>
        <div class="text-end">
          <h1 class="mb-0">
            INVOICE
          </h1>
          <b>
            {{ $sale->invoice_no }}
          </b>
          <br>
          <span class="text-secondary">
            {{ $sale->created_at ? $sale->created_at->format('d/m/Y H:i') : '-' }}
          </span>
          <br>
          <span class="badge bg-blue-lt">
            {{ $idx + 1 }} / {{ $sales->count() }}
          </span>
          <span class="badge bg-{{ ($sale->payment_status ?? '') === 'lunas' ? 'green' : 'yellow' }}-lt ms-1">
            {{ strtoupper($sale->payment_status ?? '-') }}
          </span>
        </div>
      </div>

      <div class="row mb-3">
        <div class="col-6">
          <b>
            Ditagihkan kepada:
          </b>
          <br>
          {{ $sale->customer->name ?? 'Pelanggan Umum' }}
          <br>
          <span class="text-secondary">
            {{ $sale->customer->phone ?? '' }} {{ $sale->customer->address ?? '' }}
          </span>
        </div>
        <div class="col-6 text-end">
          <b>
            Kasir:
          </b>
          {{ $sale->user->name ?? '-' }}
          <br>
          <b>
            Pembayaran:
          </b>
          {{ strtoupper($sale->payment_method ?? '-') }}
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-bordered">
          <thead>
            <tr>
              <th>
                #
              </th>
              <th>
                Deskripsi
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
            @forelse($sale->items as $i => $it)
              <tr>
                <td>
                  {{ $i + 1 }}
                </td>
                <td>
                  {{ $it->description ?? ($it->product->name ?? '-') }}
                </td>
                <td class="text-end">
                  {{ (int) $it->qty }}
                </td>
                <td class="text-end">
                  Rp {{ number_format((float) $it->price, 0, ',', '.') }}
                </td>
                <td class="text-end">
                  Rp {{ number_format((float) $it->subtotal, 0, ',', '.') }}
                </td>
              </tr>
            @empty
              <tr>
                <td
                  colspan="5"
                  class="text-center text-secondary"
                >
                  Tanpa rincian item.
                </td>
              </tr>
            @endforelse
          </tbody>
          <tfoot>
            <tr>
              <td
                colspan="4"
                class="text-end"
              >
                Subtotal
              </td>
              <td class="text-end">
                Rp {{ number_format((float) ($sale->subtotal ?? $sale->total), 0, ',', '.') }}
              </td>
            </tr>
            <tr>
              <td
                colspan="4"
                class="text-end"
              >
                Diskon
              </td>
              <td class="text-end">
                Rp {{ number_format((float) ($sale->discount ?? 0), 0, ',', '.') }}
              </td>
            </tr>
            <tr class="table-light">
              <td
                colspan="4"
                class="text-end"
              >
                <b>
                  TOTAL
                </b>
              </td>
              <td class="text-end">
                <b>
                  Rp {{ number_format((float) $sale->total, 0, ',', '.') }}
                </b>
              </td>
            </tr>
            <tr>
              <td
                colspan="4"
                class="text-end"
              >
                Dibayar
              </td>
              <td class="text-end">
                Rp {{ number_format((float) ($sale->paid_amount ?? 0), 0, ',', '.') }}
              </td>
            </tr>
            <tr>
              <td
                colspan="4"
                class="text-end"
              >
                Sisa
              </td>
              <td class="text-end">
                <b>
                  Rp {{ number_format((float) ($sale->remaining ?? 0), 0, ',', '.') }}
                </b>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>

      <div class="row mt-4">
        <div class="col-6 text-secondary small">
          Garansi toko & retur maks 7 hari dengan invoice asli.
        </div>
        <div class="col-6 text-center">
          Hormat kami,
          <br>
          {{ $sale->store->name ?? ($store->name ?? 'ServiceHP') }}
          <br>
          <br>
          <br>
          ___________________
        </div>
      </div>
    </section>
  @endforeach

  <div class="text-center no-print mt-2 d-flex gap-2 justify-content-center">
    <button
      class="btn btn-primary"
      onclick="window.print()"
    >
      🖨️ Cetak Semua ({{ $sales->count() }})
    </button>
    <a
      class="btn btn-outline-secondary"
      href="{{ route('sales.index') }}"
    >
      Kembali
    </a>
  </div>
</div>
</body>
</html>
