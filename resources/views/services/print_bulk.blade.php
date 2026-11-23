<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>
  Cetak Tiket Massal ({{ $tickets->count() }} tiket) — ServiceHP
</title>
<link href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<style>
  .bulk-box {
    max-width: 210mm;
    margin: auto;
    background: #fff;
    color: #000;
  }
  .ticket-block {
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 16px 18px;
    margin-bottom: 18px;
  }
  .ticket-block table {
    width: 100%;
  }
  .ticket-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    border-bottom: 1px dashed #adb5bd;
    padding-bottom: 8px;
    margin-bottom: 8px;
  }
  @media print {
    .no-print {
      display: none !important;
    }
    body {
      background: #fff;
    }
    .bulk-box {
      max-width: 100%;
      margin: 0;
    }
    .ticket-block {
      border: none;
      border-radius: 0;
      padding: 0;
      margin-bottom: 0;
      page-break-after: always;
    }
    .ticket-block:last-child {
      page-break-after: auto;
    }
    @page {
      size: A4;
      margin: 10mm;
    }
  }
</style>
</head>
<body class="bg-light">
<div class="bulk-box p-4 shadow-sm my-3">
  <div class="no-print alert alert-info d-flex justify-content-between align-items-center">
    <div>
      <b>
        {{ $tickets->count() }} tiket terpilih
      </b>
      <span class="text-secondary">
        — ringkas: tiket + device + status + barcode
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
        href="{{ route('services.index') }}"
      >
        Kembali
      </a>
    </div>
  </div>

  @foreach($tickets as $idx => $t)
    <section class="ticket-block">
      <div class="ticket-head">
        <div>
          <h3 class="mb-0">
            {{ $t->store->name ?? 'ServiceHP' }}
          </h3>
          <div class="text-secondary small">
            {{ $t->store->phone ?? '' }}
            ·
            {{ $t->created_at ? $t->created_at->format('d/m/Y H:i') : '-' }}
          </div>
        </div>
        <div class="text-end">
          <b>
            {{ $t->ticket_no }}
          </b>
          <br>
          <span class="badge bg-blue-lt">
            {{ $idx + 1 }} / {{ $tickets->count() }}
          </span>
          <span class="badge bg-green-lt ms-1">
            {{ strtoupper($t->status ?? '-') }}
          </span>
        </div>
      </div>
      <table class="table table-sm mb-1">
        <tbody>
          <tr>
            <th style="width:30%">
              Tiket
            </th>
            <td>
              <b>
                {{ $t->ticket_no }}
              </b>
              <span class="text-secondary">
                ({{ $t->created_at ? $t->created_at->format('d/m/Y H:i') : '-' }})
              </span>
            </td>
          </tr>
          <tr>
            <th>
              Pelanggan
            </th>
            <td>
              {{ $t->customer->name ?? '-' }}
              <span class="text-secondary">
                ({{ $t->customer->phone ?? '-' }})
              </span>
            </td>
          </tr>
          <tr>
            <th>
              Device
            </th>
            <td>
              {{ trim(($t->product_brand ?? '') . ' ' . ($t->product_type ?? '')) ?: '-' }}
              @if(!empty($t->imei))
                <span class="text-secondary">
                  · IMEI {{ $t->imei }}
                </span>
              @endif
            </td>
          </tr>
          <tr>
            <th>
              Keluhan
            </th>
            <td>
              {{ \Str::limit($t->complaint ?? '-', 120) }}
            </td>
          </tr>
          <tr>
            <th>
              Status
            </th>
            <td>
              <b>
                {{ strtoupper($t->status ?? '-') }}
              </b>
              @if(!empty($t->technician->name))
                <span class="text-secondary">
                  · Teknisi {{ $t->technician->name }}
                </span>
              @endif
            </td>
          </tr>
          <tr>
            <th>
              Estimasi / Total
            </th>
            <td>
              Rp {{ number_format((float) ($t->estimate_cost ?? 0), 0, ',', '.') }}
              /
              Rp {{ number_format((float) ($t->total_cost ?? 0), 0, ',', '.') }}
            </td>
          </tr>
        </tbody>
      </table>
      <div class="text-center mt-1">
        <svg
          class="barcode-svg"
          data-value="{{ $t->ticket_no }}"
        ></svg>
        <div class="small text-secondary">
          {{ url('/track') }}?no={{ $t->ticket_no }}
        </div>
      </div>
    </section>
  @endforeach

  <div class="text-center no-print mt-2 d-flex gap-2 justify-content-center">
    <button
      class="btn btn-primary"
      onclick="window.print()"
    >
      🖨️ Cetak Semua ({{ $tickets->count() }})
    </button>
    <a
      class="btn btn-outline-secondary"
      href="{{ route('services.index') }}"
    >
      Kembali
    </a>
  </div>
</div>
<script>
try {
  document.querySelectorAll('.barcode-svg').forEach(function (el) {
    JsBarcode(el, el.getAttribute('data-value'), {
      format: 'CODE128',
      height: 44,
      width: 1.4,
      fontSize: 11
    });
  });
} catch (e) {}
</script>
</body>
</html>
