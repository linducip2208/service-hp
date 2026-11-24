<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
@php
  $nHutang = $debts->where('type', 'hutang')->count();
  $nPiutang = $debts->where('type', 'piutang')->count();
@endphp
<title>
  Cetak Surat Massal ({{ $debts->count() }} surat) — {{ $store->name ?? 'ServiceHP' }}
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
        {{ $debts->count() }} surat
        ({{ $nHutang }} hutang + {{ $nPiutang }} piutang)
      </b>
      <span class="text-secondary">
        — total sisa Rp {{ number_format($debts->sum('remaining'), 0, ',', '.') }}
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
        href="{{ route('debts.index') }}"
      >
        Kembali
      </a>
    </div>
  </div>

  @foreach($debts as $idx => $debt)
    @php($payments = $paymentsByDebt->get($debt->id, collect()))
    @php
      $isHutang = ($debt->type ?? 'piutang') === 'hutang';
      $prefix = $isHutang ? 'HUT' : 'PIU';
      $judul = $isHutang ? 'SURAT PERNYATAAN HUTANG' : 'SURAT TAGIHAN';
      $nomor = $prefix . '-' . str_pad($debt->id, 6, '0', STR_PAD_LEFT);
    @endphp
    <section class="bill-block">
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
            {{ $judul }}
          </h1>
          <b>
            No. {{ $nomor }}
          </b>
          <br>
          <span class="text-secondary">
            {{ now()->format('d F Y') }}
          </span>
          <br>
          <span class="badge bg-blue-lt">
            {{ $idx + 1 }} / {{ $debts->count() }}
          </span>
          <span class="badge bg-{{ $isHutang ? 'red' : 'green' }}-lt ms-1">
            {{ $debt->type }}
          </span>
        </div>
      </div>

      @if($isHutang)
        <p>
          Kepada Yth.
          <br>
          <b>
            {{ $debt->party_name }}
          </b>
          <br>
          <span class="text-secondary">
            (Supplier / Pemberi Hutang)
          </span>
        </p>
        <p>
          Dengan hormat, bersama surat ini kami dari
          <b>{{ $store->name ?? 'ServiceHP' }}</b>
          menyatakan memiliki hutang kepada
          <b>{{ $debt->party_name }}</b>
          dengan rincian sebagai berikut:
        </p>
      @else
        <p>
          Kepada Yth.
          <br>
          <b>
            {{ $debt->party_name }}
          </b>
        </p>
        <p>
          Dengan hormat, bersama surat ini kami menyampaikan tagihan piutang
          dengan rincian sebagai berikut:
        </p>
      @endif

      <table class="table table-bordered">
        <tbody>
          <tr>
            <th style="width:35%">
              Nomor surat
            </th>
            <td>
              {{ $nomor }}
            </td>
          </tr>
          <tr>
            <th>
              {{ $isHutang ? 'Nama supplier' : 'Nama pihak' }}
            </th>
            <td>
              {{ $debt->party_name }}
            </td>
          </tr>
          <tr>
            <th>
              {{ $isHutang ? 'Total hutang' : 'Total tagihan' }}
            </th>
            <td class="text-end">
              Rp {{ number_format($debt->total, 0, ',', '.') }}
            </td>
          </tr>
          <tr>
            <th>
              {{ $isHutang ? 'Sudah dicicil / dibayar' : 'Sudah dibayar' }}
            </th>
            <td class="text-end">
              Rp {{ number_format($debt->paid, 0, ',', '.') }}
            </td>
          </tr>
          <tr>
            <th>
              {{ $isHutang ? 'Sisa hutang' : 'Sisa tagihan' }}
            </th>
            <td class="text-end">
              <b>
                Rp {{ number_format($debt->remaining, 0, ',', '.') }}
              </b>
            </td>
          </tr>
          <tr>
            <th>
              Status
            </th>
            <td>
              <span class="badge bg-{{ $debt->status === 'lunas' ? 'green' : ($debt->status === 'belum' ? 'red' : 'yellow') }}-lt">
                {{ $debt->status }}
              </span>
            </td>
          </tr>
          <tr>
            <th>
              {{ $isHutang ? 'Janji / jatuh tempo bayar' : 'Jatuh tempo' }}
            </th>
            <td>
              {{ $debt->due_date ? $debt->due_date->format('d/m/Y') : '-' }}
              @if($debt->due_date && $debt->status !== 'lunas' && $debt->due_date->isPast())
                <span class="badge bg-red-lt ms-2">
                  Terlambat
                </span>
              @endif
            </td>
          </tr>
          <tr>
            <th>
              Tanggal dicatat
            </th>
            <td>
              {{ $debt->created_at ? $debt->created_at->format('d/m/Y H:i') : '-' }}
            </td>
          </tr>
        </tbody>
      </table>

      <h3 class="mt-4">
        {{ $isHutang ? 'Riwayat Cicilan / Pembayaran Hutang' : 'Riwayat Pembayaran' }}
      </h3>
      @if($payments->count())
        <div class="table-responsive">
          <table class="table table-bordered">
            <thead>
              <tr>
                <th>
                  #
                </th>
                <th>
                  Tanggal
                </th>
                <th class="text-end">
                  Nominal
                </th>
                <th>
                  Keterangan
                </th>
              </tr>
            </thead>
            <tbody>
              @foreach($payments as $i => $p)
                @php
                  $buktiBulkPath = null;
                  if (preg_match('/\[BUKTI:(.*?)\]/', $p->description ?? '', $mBulk)) {
                    $buktiBulkPath = trim($mBulk[1]);
                  }
                  $descBulkBersih = trim(preg_replace('/\[BUKTI:.*?\]/', '', $p->description ?? ''));
                @endphp
                <tr>
                  <td>
                    {{ $i + 1 }}
                  </td>
                  <td>
                    {{ $p->created_at ? $p->created_at->format('d/m/Y H:i') : '-' }}
                  </td>
                  <td class="text-end">
                    Rp {{ number_format($p->amount, 0, ',', '.') }}
                  </td>
                  <td>
                    {{ $descBulkBersih !== '' ? $descBulkBersih : '-' }}
                    @if($buktiBulkPath)
                      <div class="mt-1 no-print">
                        <a href="{{ asset('storage/' . $buktiBulkPath) }}" target="_blank">
                          📎 Lihat bukti
                        </a>
                      </div>
                    @endif
                  </td>
                </tr>
              @endforeach
            </tbody>
            <tfoot>
              <tr class="table-light">
                <td
                  colspan="2"
                  class="text-end"
                >
                  <b>
                    Total terbayar (riwayat)
                  </b>
                </td>
                <td class="text-end">
                  <b>
                    Rp {{ number_format($payments->sum('amount'), 0, ',', '.') }}
                  </b>
                </td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>
      @else
        <p class="text-secondary">
          {{ $isHutang
            ? 'Belum ada riwayat cicilan hutang yang terlacak untuk surat ini.'
            : 'Belum ada riwayat pembayaran yang terlacak untuk tagihan ini.' }}
        </p>
      @endif

      @if($isHutang)
        <p class="mt-3">
          Kami berjanji akan melunasi sisa hutang sebesar
          <b>
            Rp {{ number_format($debt->remaining, 0, ',', '.') }}
          </b>
          kepada
          <b>
            {{ $debt->party_name }}
          </b>
          @if($debt->due_date)
            paling lambat pada
            <b>
              {{ $debt->due_date->format('d/m/Y') }}
            </b>.
          @else
            segera.
          @endif
          Demikian surat pernyataan hutang ini kami buat dengan sebenar-benarnya.
        </p>
      @else
        <p class="mt-3">
          Mohon pembayaran sisa sebesar
          <b>
            Rp {{ number_format($debt->remaining, 0, ',', '.') }}
          </b>
          @if($debt->due_date)
            sebelum
            <b>
              {{ $debt->due_date->format('d/m/Y') }}
            </b>.
          @else
            segera.
          @endif
          Terima kasih atas perhatian dan kerja samanya.
        </p>
      @endif

      <div class="row mt-5">
        @if($isHutang)
          <div class="col-6 text-center text-secondary">
            Pemberi Hutang (Supplier),
            <br>
            <br>
            <br>
            <br>
            ___________________
            <br>
            <b>
              {{ $debt->party_name }}
            </b>
          </div>
          <div class="col-6 text-center">
            Yang Berhutang,
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
        @else
          <div class="col-6 text-center text-secondary">
            Penerima,
            <br>
            <br>
            <br>
            <br>
            ___________________
            <br>
            <b>
              {{ $debt->party_name }}
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
        @endif
      </div>
    </section>
  @endforeach

  <div class="text-center no-print mt-2 d-flex gap-2 justify-content-center">
    <button
      class="btn btn-primary"
      onclick="window.print()"
    >
      🖨️ Cetak Semua ({{ $debts->count() }})
    </button>
    <a
      class="btn btn-outline-secondary"
      href="{{ route('debts.index') }}"
    >
      Kembali
    </a>
  </div>
</div>
</body>
</html>
