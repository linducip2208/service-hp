<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>
  Cetak Perjanjian Massal ({{ $rows->count() }} dokumen)
</title>
<link
  href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css"
  rel="stylesheet"
>
<style>
  .letter-box {
    max-width: 210mm;
    margin: auto;
    background: #fff;
    color: #000;
  }
  .agreement-block {
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 22px;
    margin-bottom: 18px;
    font-size: 14px;
    line-height: 1.7;
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
    .agreement-block {
      border: none;
      border-radius: 0;
      padding: 0;
      margin-bottom: 0;
      page-break-after: always;
    }
    .agreement-block:last-child {
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
        {{ $rows->count() }} perjanjian terpilih (cicilan/DP)
      </b>
      <span class="text-secondary">
        — 1 halaman gabungan, page-break per dokumen
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

  @foreach($rows as $idx => $row)
    @php
      $sale = $row['sale'];
      $tenor = $row['tenor'];
      $total = $row['total'];
      $paid = $row['paid'];
      $remaining = $row['remaining'];
      $angsuran = $row['angsuran'];
      $sigPath = $row['sigPath'];
      $noteBersih = $row['noteBersih'];
    @endphp
    <section class="agreement-block">
      <div class="text-center mb-1">
        <div class="fw-bold fs-2">
          SURAT PERJANJIAN KREDIT BARANG
        </div>
        <div class="font-monospace text-secondary">
          No: {{ $sale->invoice_no }}/SPK/{{ $sale->created_at->format('m/Y') }}
        </div>
        <div>
          <span class="badge bg-blue-lt">
            {{ $idx + 1 }} / {{ $rows->count() }}
          </span>
          <span class="badge bg-yellow-lt ms-1">
            {{ strtoupper($sale->payment_status ?? $sale->payment_method ?? '-') }}
          </span>
        </div>
      </div>
      <p class="mt-3 mb-2">
        Pada hari ini, {{ $sale->created_at->translatedFormat('l, d F Y') }}, bertempat di
        {{ $sale->store->name ?? $store->name ?? 'toko' }}, telah disepakati perjanjian kredit antara:
      </p>
      <table class="table table-sm mb-3">
        <tbody>
          <tr>
            <th class="w-25">
              PIHAK PERTAMA (Penjual)
            </th>
            <td>
              <div>
                {{ $sale->store->name ?? $store->name ?? '—' }}
              </div>
              @if(($sale->store->address ?? $store->address ?? false))
                <div class="small text-secondary">
                  {{ $sale->store->address ?? $store->address }}
                </div>
              @endif
              @if(($sale->store->phone ?? $store->phone ?? false))
                <div class="small text-secondary">
                  Telp: {{ $sale->store->phone ?? $store->phone }}
                </div>
              @endif
              <div class="small text-secondary">
                Diwakili kasir: {{ $sale->user->name ?? '—' }}
              </div>
            </td>
          </tr>
          <tr>
            <th>
              PIHAK KEDUA (Pembeli)
            </th>
            <td>
              <div>
                {{ $sale->customer->name ?? 'Umum' }}
              </div>
              @if($sale->customer->phone ?? false)
                <div class="small text-secondary">
                  Telp: {{ $sale->customer->phone }}
                </div>
              @endif
              @if($sale->customer->address ?? false)
                <div class="small text-secondary">
                  {{ $sale->customer->address }}
                </div>
              @endif
            </td>
          </tr>
        </tbody>
      </table>
      <p class="fw-bold mb-1">
        Pasal 1 — Objek Perjanjian
      </p>
      <p>
        PIHAK PERTAMA menjual dan PIHAK KEDUA membeli barang berikut secara kredit:
      </p>
      <ul>
        @foreach($sale->items as $it)
          <li>
            {{ $it->description }} ({{ (int) $it->qty }}x @ Rp {{ number_format($it->price, 0, ',', '.') }})
            @if($it->productItem && $it->productItem->imei)
              — IMEI/SN: {{ $it->productItem->imei }}
            @endif
          </li>
        @endforeach
      </ul>
      <p>
        Barang diserahterimakan dalam kondisi baik pada tanggal transaksi di atas.
        Risiko kerusakan/kehilangan setelah serah terima menjadi tanggung jawab PIHAK KEDUA.
      </p>
      <p class="fw-bold mb-1">
        Pasal 2 — Harga, Tenor &amp; Angsuran
      </p>
      <table class="table table-sm mb-2">
        <tbody>
          <tr>
            <th class="w-50">
              Total harga{{ $sale->discount > 0 ? ' (setelah diskon Rp ' . number_format($sale->discount, 0, ',', '.') . ')' : '' }}
            </th>
            <td class="text-end">
              Rp {{ number_format($total, 0, ',', '.') }}
            </td>
          </tr>
          <tr>
            <th>
              Uang muka / sudah dibayar ({{ strtoupper($sale->payment_method) }})
            </th>
            <td class="text-end">
              Rp {{ number_format($paid, 0, ',', '.') }}
            </td>
          </tr>
          <tr>
            <th>
              Sisa kredit
            </th>
            <td class="text-end">
              Rp {{ number_format($remaining, 0, ',', '.') }}
            </td>
          </tr>
          <tr>
            <th>
              Tenor
            </th>
            <td class="text-end">
              {{ $tenor > 0 ? $tenor . ' bulan' : '— (lunas / tanpa tenor)' }}
            </td>
          </tr>
          <tr>
            <th>
              Angsuran per bulan (estimasi total ÷ tenor)
            </th>
            <td class="text-end">
              Rp {{ number_format($angsuran, 0, ',', '.') }}
            </td>
          </tr>
        </tbody>
      </table>
      @if($noteBersih)
        <p class="small text-secondary">
          Catatan transaksi: {{ $noteBersih }}
        </p>
      @endif
      <p class="fw-bold mb-1">
        Pasal 3 — Pembayaran &amp; Keterlambatan (Tanpa Denda)
      </p>
      <ol>
        <li>
          PIHAK KEDUA wajib membayar angsuran setiap bulan sampai sisa kredit lunas,
          dengan bukti pembayaran (kwitansi KWT-…) sebagai tanda sah.
        </li>
        <li>
          Perjanjian ini <b>tidak memberlakukan denda finansial</b> atas keterlambatan —
          tidak ada bunga denda maupun biaya tambahan apa pun.
        </li>
        <li>
          Bila terlambat, PIHAK PERTAMA menyampaikan teguran secara patut
          (lisan/tertulis/WA) dan kedua pihak bermusyawarah menentukan jadwal susulan.
        </li>
        <li>
          Keterlambatan berulang menjadi dasar PIHAK PERTAMA menahan layanan
          garansi/retur sampai tunggakan diselesaikan, tanpa menyita barang sepihak.
        </li>
      </ol>
      <p class="fw-bold mb-1">
        Pasal 4 — Pelunasan Dipercepat
      </p>
      <ol>
        <li>
          PIHAK KEDUA dapat melunasi seluruh sisa kredit sewaktu-waktu sebelum tenor berakhir.
        </li>
        <li>
          Pelunasan dipercepat <b>tidak dikenakan penalti/biaya tambahan</b>;
          status transaksi menjadi LUNAS dan kwitansi pelunasan diterbitkan.
        </li>
      </ol>
      <p class="fw-bold mb-1">
        Pasal 5 — Kepemilikan &amp; Peralihan Barang
      </p>
      <ol>
        <li>
          Barang menjadi milik PIHAK KEDUA sejak serah terima, namun PIHAK KEDUA
          dilarang menjual/menggadaikan barang selama sisa kredit belum lunas
          tanpa persetujuan tertulis PIHAK PERTAMA.
        </li>
        <li>
          Kerusakan akibat pemakaian menjadi beban PIHAK KEDUA dan tidak
          menghapus kewajiban angsuran.
        </li>
      </ol>
      <p class="fw-bold mb-1">
        Pasal 6 — Perselisihan &amp; Penutup
      </p>
      <p>
        Segala perselisihan diselesaikan secara musyawarah. Bila tidak tercapai,
        kedua pihak sepakat menyelesaikannya sesuai ketentuan hukum yang berlaku.
        Surat ini dibuat rangkap dua dengan kekuatan hukum sama,
        ditandatangani kedua pihak di atas materai secukupnya bila nilai transaksi
        mewajibkan (catatan: tempelkan materai + tanda tangan di area TTD di bawah).
      </p>
      <div class="row mt-4">
        <div class="col-6 text-center">
          <div class="small text-secondary">
            PIHAK PERTAMA (Penjual),
          </div>
          <div style="height: 72px;">
          </div>
          <div>
            ( {{ $sale->user->name ?? '....................' }} )
          </div>
          <div class="small text-secondary">
            {{ $sale->store->name ?? $store->name ?? '' }}
          </div>
        </div>
        <div class="col-6 text-center">
          <div class="small text-secondary">
            PIHAK KEDUA (Pembeli),
          </div>
          @if($sigPath)
            <div>
              <img
                src="{{ asset('storage/' . $sigPath) }}"
                alt="TTD persetujuan pembeli"
                style="max-height: 64px; background: #fff; border: 1px solid #e5e7eb; border-radius: 6px; padding: 2px;"
              >
            </div>
          @else
            <div style="height: 72px;">
            </div>
          @endif
          <div>
            ( {{ $sale->customer->name ?? '....................' }} )
          </div>
          <div class="small text-secondary">
            Materai Rp10.000 + ttd (bila wajib)
          </div>
        </div>
      </div>
      <div class="small text-secondary mt-3">
        Invoice {{ $sale->invoice_no }} • {{ $sale->payments->count() }}x pembayaran tercatat •
        sisa Rp {{ number_format($remaining, 0, ',', '.') }}
      </div>
    </section>
  @endforeach

  <div class="text-center no-print mt-2 d-flex gap-2 justify-content-center">
    <button
      class="btn btn-primary"
      onclick="window.print()"
    >
      🖨️ Cetak Semua ({{ $rows->count() }})
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
