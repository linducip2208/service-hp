@extends('layouts.tabler')
@section('title', ($transaction->type === 'masuk' ? 'Bukti Kas Masuk' : 'Bukti Kas Keluar') . ' #' . $transaction->id)
@section('content')
<div class="page-header d-print-none">
  <div class="row align-items-center">
    <div class="col">
      <div class="page-pretitle">Keuangan / Kas</div>
      <h2 class="page-title">
        {{ $transaction->type === 'masuk' ? 'Bukti Kas Masuk' : 'Bukti Kas Keluar' }}
        <span class="badge bg-{{ $transaction->type === 'masuk' ? 'green' : 'red' }}-lt ms-1">{{ $transaction->type }}</span>
      </h2>
      <div class="text-secondary">No. KAS-{{ str_pad($transaction->id, 6, '0', STR_PAD_LEFT) }}</div>
    </div>
    <div class="col-auto d-flex gap-2">
      <a href="{{ route('cash.index') }}" class="btn btn-outline-secondary">← Mutasi Kas</a>
      <button class="btn btn-primary" onclick="window.print()">🖨 Cetak Voucher</button>
    </div>
  </div>
</div>

@php
    $buktiKasPath = null;
    if (preg_match('/\[BUKTI:(.*?)\]/', $transaction->description ?? '', $mKas)) {
        $buktiKasPath = trim($mKas[1]);
    }
    $descKasBersih = trim(preg_replace('/\[BUKTI:.*?\]/', '', $transaction->description ?? ''));
@endphp
<div class="card">
  <div class="card-body">
    <div class="text-center mb-3">
      <h3 class="mb-1">{{ strtoupper($transaction->type === 'masuk' ? 'BUKTI KAS MASUK' : 'BUKTI KAS KELUAR') }}</h3>
      <div class="text-secondary">
        {{ $transaction->store->name ?? 'Toko' }}
        • No. KAS-{{ str_pad($transaction->id, 6, '0', STR_PAD_LEFT) }}
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-bordered table-vcenter">
        <tbody>
          <tr>
            <th style="width:220px">No. Bukti</th>
            <td>KAS-{{ str_pad($transaction->id, 6, '0', STR_PAD_LEFT) }}</td>
            <th style="width:220px">Tanggal</th>
            <td>{{ $transaction->created_at?->format('d/m/Y H:i') ?? '-' }}</td>
          </tr>
          <tr>
            <th>Akun Kas</th>
            <td>{{ $transaction->cashAccount->name ?? '-' }}</td>
            <th>Kategori</th>
            <td>{{ ucfirst($transaction->category ?? '-') }}</td>
          </tr>
          <tr>
            <th>Jumlah</th>
            <td colspan="3">
              <b>Rp {{ number_format($transaction->amount, 0, ',', '.') }}</b>
              <div class="text-secondary fst-italic">
                Terbilang: {{ ucfirst($terbilang ?: 'nol') }} rupiah
              </div>
            </td>
          </tr>
          <tr>
            <th>Keperluan / Keterangan</th>
            <td colspan="3">
                {{ $descKasBersih ?: '-' }}
                @if($buktiKasPath)
                    <div class="mt-2">
                        <img
                            src="{{ asset('storage/' . $buktiKasPath) }}"
                            alt="Foto bukti kas KAS-{{ str_pad($transaction->id, 6, '0', STR_PAD_LEFT) }}"
                            class="rounded border"
                            style="max-width: 320px; width: 100%; height: auto; object-fit: contain;"
                            loading="lazy"
                        >
                        <div class="text-secondary small mt-1">
                            Foto bukti terlampir — klik untuk perbesar.
                        </div>
                        <div>
                            <a
                                href="{{ asset('storage/' . $buktiKasPath) }}"
                                target="_blank"
                                class="small d-print-none"
                            >
                                Buka foto bukti ukuran penuh
                            </a>
                        </div>
                    </div>
                @endif
            </td>
          </tr>
          <tr>
            <th>{{ $transaction->type === 'masuk' ? 'Diterima dari (pembayar)' : 'Dibayarkan kepada (penerima)' }}</th>
            <td>
              @if($transaction->ref_type === 'transfer')
                {{ $descKasBersih ?: '-' }}
              @else
                {{ $descKasBersih ? strtok($descKasBersih, "—-") : '-' }}
              @endif
              <div class="text-secondary small">Diisi manual saat serah terima bila kolom ini masih kosong.</div>
            </td>
            <th>Dicatat oleh</th>
            <td>{{ $transaction->user->name ?? '-' }}</td>
          </tr>
          @if($transaction->ref_type)
          <tr>
            <th>Referensi</th>
            <td colspan="3">
              {{ ucfirst($transaction->ref_type) }}
              @if($transaction->ref_id)#{{ $transaction->ref_id }}@endif
            </td>
          </tr>
          @endif
        </tbody>
      </table>
    </div>

    <div class="row mt-5 text-center">
      <div class="col-4">
        <p class="mb-5">Kasir<br><span class="text-secondary small">{{ now()->format('d/m/Y') }}</span></p>
        <p>( .............................. )</p>
        <p class="text-secondary small">{{ $transaction->user->name ?? '' }}</p>
      </div>
      <div class="col-4">
        <p class="mb-5">{{ $transaction->type === 'masuk' ? 'Pembayar' : 'Penerima' }}</p>
        <p>( .............................. )</p>
        <p class="text-secondary small">Nama jelas & tanda tangan</p>
      </div>
      <div class="col-4">
        <p class="mb-5">Mengetahui / Owner</p>
        <p>( .............................. )</p>
        <p class="text-secondary small">Nama jelas & tanda tangan</p>
      </div>
    </div>

    <p class="text-secondary small mt-4 mb-0 d-print-none">
      Voucher ini dicetak dari mutasi kas #{{ $transaction->id }}.
      Simpan sebagai arsip bukti kas masuk/keluar toko.
    </p>
  </div>
</div>

<style>
@media print {
  .navbar, .sidebar, .page-header { display: none !important; }
  .card { border: 1px solid #000 !important; box-shadow: none !important; }
}
</style>
@endsection
