<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
@php
  $nMasuk = $transactions->where('type', 'masuk')->count();
  $nKeluar = $transactions->where('type', 'keluar')->count();
@endphp
<title>
  Cetak Voucher Kas Massal ({{ $transactions->count() }} voucher) — {{ $store->name ?? 'ServiceHP' }}
</title>
<link href="/vendor/tabler/tabler.min.css" rel="stylesheet">
<style>
  .voucher-box {
    max-width: 210mm;
    margin: auto;
    background: #fff;
    color: #000;
  }
  .voucher-block {
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
    .voucher-box {
      max-width: 100%;
      margin: 0;
    }
    .voucher-block {
      border: none;
      border-radius: 0;
      padding: 0;
      margin-bottom: 0;
      page-break-after: always;
    }
    .voucher-block:last-child {
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
<div class="voucher-box p-4 shadow-sm my-3">
  <div class="no-print alert alert-info d-flex justify-content-between align-items-center">
    <div>
      <b>
        {{ $transactions->count() }} voucher
        ({{ $nMasuk }} masuk + {{ $nKeluar }} keluar)
      </b>
      <span class="text-secondary">
        — total Rp {{ number_format($transactions->sum('amount'), 0, ',', '.') }}
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
        href="{{ route('cash.index') }}"
      >
        Kembali
      </a>
    </div>
  </div>

  @foreach($transactions as $idx => $t)
    @php
      $isMasuk = ($t->type ?? 'masuk') === 'masuk';
      $judul = $isMasuk ? 'BUKTI KAS MASUK' : 'BUKTI KAS KELUAR';
      $nomor = 'KAS-' . str_pad($t->id, 6, '0', STR_PAD_LEFT);
      $terbilang = $terbilangById[$t->id] ?? '';
      $buktiBulkPath = null;
      if (preg_match('/\[BUKTI:(.*?)\]/', $t->description ?? '', $mBulk)) {
          $buktiBulkPath = trim($mBulk[1]);
      }
      $descBulkBersih = trim(preg_replace('/\[BUKTI:.*?\]/', '', $t->description ?? ''));
    @endphp
    <section class="voucher-block">
      <div class="text-center mb-3">
        <h2 class="mb-1">
          {{ $judul }}
        </h2>
        <div class="text-secondary">
          {{ $t->store->name ?? ($store->name ?? 'Toko') }}
          • No. {{ $nomor }}
        </div>
        <div class="mt-1">
          <span class="badge bg-blue-lt">
            {{ $idx + 1 }} / {{ $transactions->count() }}
          </span>
          <span class="badge bg-{{ $isMasuk ? 'green' : 'red' }}-lt ms-1">
            {{ $t->type }}
          </span>
        </div>
      </div>

      <table class="table table-bordered">
        <tbody>
          <tr>
            <th style="width:220px">
              No. Bukti
            </th>
            <td>
              {{ $nomor }}
            </td>
            <th style="width:220px">
              Tanggal
            </th>
            <td>
              {{ $t->created_at?->format('d/m/Y H:i') ?? '-' }}
            </td>
          </tr>
          <tr>
            <th>
              Akun Kas
            </th>
            <td>
              {{ $t->cashAccount->name ?? '-' }}
            </td>
            <th>
              Kategori
            </th>
            <td>
              {{ ucfirst($t->category ?? '-') }}
            </td>
          </tr>
          <tr>
            <th>
              Jumlah
            </th>
            <td colspan="3">
              <b>
                Rp {{ number_format($t->amount, 0, ',', '.') }}
              </b>
              <div class="text-secondary fst-italic">
                Terbilang: {{ ucfirst($terbilang ?: 'nol') }} rupiah
              </div>
            </td>
          </tr>
          <tr>
            <th>
              Keperluan / Keterangan
            </th>
            <td colspan="3">
              {{ $descBulkBersih ?: '-' }}
              @if($buktiBulkPath)
                <div class="mt-2">
                  <img
                    src="{{ asset('storage/' . $buktiBulkPath) }}"
                    alt="Foto bukti kas {{ $nomor }}"
                    class="rounded border"
                    style="max-width: 320px; width: 100%; height: auto; object-fit: contain;"
                    loading="lazy"
                  >
                </div>
              @endif
            </td>
          </tr>
          <tr>
            <th>
              {{ $isMasuk ? 'Diterima dari (pembayar)' : 'Dibayarkan kepada (penerima)' }}
            </th>
            <td>
              @if($t->ref_type === 'transfer')
                {{ $descBulkBersih ?: '-' }}
              @else
                {{ $descBulkBersih ? strtok($descBulkBersih, "—-") : '-' }}
              @endif
            </td>
            <th>
              Dicatat oleh
            </th>
            <td>
              {{ $t->user->name ?? '-' }}
            </td>
          </tr>
          @if($t->ref_type)
            <tr>
              <th>
                Referensi
              </th>
              <td colspan="3">
                {{ ucfirst($t->ref_type) }}
                @if($t->ref_id)
                  #{{ $t->ref_id }}
                @endif
              </td>
            </tr>
          @endif
        </tbody>
      </table>

      <div class="row mt-5 text-center">
        <div class="col-4">
          <p class="mb-5">
            Kasir
            <br>
            <span class="text-secondary small">
              {{ now()->format('d/m/Y') }}
            </span>
          </p>
          <p>
            ( .............................. )
          </p>
          <p class="text-secondary small">
            {{ $t->user->name ?? '' }}
          </p>
        </div>
        <div class="col-4">
          <p class="mb-5">
            {{ $isMasuk ? 'Pembayar' : 'Penerima' }}
          </p>
          <p>
            ( .............................. )
          </p>
          <p class="text-secondary small">
            Nama jelas & tanda tangan
          </p>
        </div>
        <div class="col-4">
          <p class="mb-5">
            Mengetahui / Owner
          </p>
          <p>
            ( .............................. )
          </p>
          <p class="text-secondary small">
            Nama jelas & tanda tangan
          </p>
        </div>
      </div>
    </section>
  @endforeach

  <div class="text-center no-print mt-2 d-flex gap-2 justify-content-center">
    <button
      class="btn btn-primary"
      onclick="window.print()"
    >
      🖨️ Cetak Semua ({{ $transactions->count() }})
    </button>
    <a
      class="btn btn-outline-secondary"
      href="{{ route('cash.index') }}"
    >
      Kembali
    </a>
  </div>
</div>
</body>
</html>
