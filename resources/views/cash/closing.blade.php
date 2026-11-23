@extends('layouts.tabler')
@section('title','Tutup Kasir / Handover Shift')
@section('content')
<div class="page-header d-print-none"><div class="row align-items-center"><div class="col">
  <h2 class="page-title">Tutup Kasir / Handover Shift</h2>
  <div class="text-secondary">Rekap saldo awal vs akhir per akun kas, selisih kas, dan berita acara serah terima</div>
</div><div class="col-auto">
  <a href="{{ route('cash.index') }}" class="btn btn-outline-secondary">← Kas</a>
  <button class="btn btn-primary" onclick="window.print()">Cetak Berita Acara</button>
</div></div></div>

<div class="card mb-3 d-print-none"><div class="card-body"><form method="GET" class="row g-2 align-items-end">
  <div class="col-md-3"><label class="form-label">Tanggal shift</label><input type="date" name="tanggal" value="{{ $tanggal }}" class="form-control"></div>
  @foreach($accounts as $a)
  <div class="col-md-3"><label class="form-label">Hitung fisik: {{ $a->name }}</label>
    <input type="number" name="fisik_{{ $a->id }}" value="{{ request("fisik_{$a->id}", (int) $a->balance) }}" class="form-control" min="0"></div>
  @endforeach
  <div class="col-md-2"><button class="btn btn-outline-primary w-100">Hitung Selisih</button></div>
</form>
<hr>
<form method="GET" class="row g-2 align-items-end">
  <input type="hidden" name="tanggal" value="{{ $tanggal }}">
  <div class="col-12"><span class="form-label">Kas awal shift (modal kasir — tersimpan per akun & tanggal, tanpa ubah database)</span></div>
  @foreach($accounts as $a)
  <div class="col-md-3"><label class="form-label">Kas awal: {{ $a->name }}</label>
    <input type="number" name="awal_{{ $a->id }}" value="{{ $kasAwalSesi[$a->id] !== null ? (int) $kasAwalSesi[$a->id] : '' }}" class="form-control" min="0" placeholder="Kosong = hitung otomatis"></div>
  @endforeach
  <div class="col-md-3 d-flex gap-2">
    <button class="btn btn-success w-100"><i class="ti ti-device-floppy me-1"></i> Simpan Kas Awal</button>
    <button class="btn btn-outline-secondary" name="reset_awal" value="1" title="Kembali ke hitungan otomatis">Reset</button>
  </div>
</form></div></div>

@if(!empty($canManageLock))
<div class="card mb-3 d-print-none">
  <div class="card-header"><h3 class="card-title"><i class="ti ti-lock me-1"></i> Kunci Periode (Tutup Buku)</h3></div>
  <div class="card-body">
    @if(!empty($periodLock))
      <div class="alert alert-warning mb-2">Periode <b>terkunci</b>: transaksi bertanggal sebelum <b>{{ $periodLock }}</b> akan ditolak (tanggal dokumen &lt; batas). Transaksi tanggal ≥ batas tetap berjalan normal.</div>
    @else
      <div class="alert alert-success mb-2">Periode <b>terbuka</b>: semua tanggal bisa diinput. Isi tanggal batas lalu Simpan untuk mengunci periode.</div>
    @endif
    <form method="POST" action="{{ route('cash.closing.lock') }}" class="row g-2 align-items-end">
      @csrf
      <div class="col-md-4">
        <label class="form-label">Batas kunci (tolak tanggal &lt; batas)</label>
        <input type="date" name="lock_date" value="{{ $periodLock ?? '' }}" max="{{ date('Y-m-d') }}" class="form-control" required>
      </div>
      <div class="col-md-4 d-flex gap-2 align-items-end">
        <button class="btn btn-warning"><i class="ti ti-lock me-1"></i> Kunci Periode</button>
      </div>
    </form>
    @if(!empty($periodLock))
    <form method="POST" action="{{ route('cash.closing.unlock') }}" class="mt-2" onsubmit="return confirm('Buka kunci periode? Transaksi tanggal lama kembali bisa diinput.')">
      @csrf @method('DELETE')
      <button class="btn btn-outline-danger">Buka Kunci</button>
    </form>
    @endif
  </div>
</div>
@endif

<div class="card mb-3"><div class="card-header"><h3 class="card-title">Rekap per Akun ({{ date('d/m/Y', strtotime($tanggal)) }})</h3></div>
<div class="table-responsive"><table class="table table-vcenter card-table"><thead><tr>
  <th>Akun</th><th class="text-end">Saldo Awal</th><th class="text-end">Masuk Hari Ini</th><th class="text-end">Keluar Hari Ini</th><th class="text-end">Saldo Sistem</th><th class="text-end">Fisik</th><th class="text-end">Selisih</th>
</tr></thead><tbody>
@foreach($rows as $r)<tr>
  <td>{{ $r['account']->name }}</td>
  <td class="text-end">{{ number_format($r['awal'],0,',','.') }}@if(!empty($r['dariSesi']))<div><span class="badge bg-blue-lt">kas awal shift</span></div>@endif</td>
  <td class="text-end text-success">+{{ number_format($r['masuk'],0,',','.') }}</td>
  <td class="text-end text-danger">-{{ number_format($r['keluar'],0,',','.') }}</td>
  <td class="text-end"><b>{{ number_format($r['akhir'],0,',','.') }}</b></td>
  <td class="text-end">{{ number_format($r['fisik'],0,',','.') }}</td>
  <td class="text-end"><span class="badge bg-{{ $r['selisih']==0?'green':($r['selisih']>0?'blue':'red') }}-lt">{{ ($r['selisih']>0?'+':'').number_format($r['selisih'],0,',','.') }}</span></td>
</tr>@endforeach
<tr class="table-light"><td><b>TOTAL</b></td>
  <td class="text-end"><b>{{ number_format($grandAwal,0,',','.') }}</b></td>
  <td class="text-end text-success"><b>+{{ number_format($grandMasuk,0,',','.') }}</b></td>
  <td class="text-end text-danger"><b>-{{ number_format($grandKeluar,0,',','.') }}</b></td>
  <td class="text-end"><b>{{ number_format($grandAkhir,0,',','.') }}</b></td>
  <td class="text-end"><b>{{ number_format($grandFisik,0,',','.') }}</b></td>
  <td class="text-end"><b>{{ ($grandSelisih>0?'+':'').number_format($grandSelisih,0,',','.') }}</b></td></tr>
</tbody></table></div></div>

<div class="card"><div class="card-body">
  <h3 class="text-center mb-1">BERITA ACARA SERAH TERIMA KAS</h3>
  <p class="text-center text-secondary">Tanggal: {{ date('d/m/Y', strtotime($tanggal)) }} — Kasir: {{ $kasir->name ?? '-' }}</p>
  <p>Pada hari ini telah dilakukan perhitungan dan serah terima kas shift dengan rincian total sistem <b>Rp {{ number_format($grandAkhir,0,',','.') }}</b>, fisik <b>Rp {{ number_format($grandFisik,0,',','.') }}</b>, selisih <b>Rp {{ ($grandSelisih>0?'+':'').number_format($grandSelisih,0,',','.') }}</b>.</p>
  <p class="small text-secondary">Kas awal shift: @foreach($rows as $r){{ $r['account']->name }} Rp {{ number_format($r['awal'],0,',','.') }}{{ !empty($r['dariSesi']) ? ' (input kasir)' : ' (otomatis)' }}{{ !$loop->last ? ' • ' : '' }}@endforeach</p>
  <div class="row mt-5 text-center">
    <div class="col-6"><p>Kasir Shift</p><br><br><p>( .............................. )</p></div>
    <div class="col-6"><p>Penerima / Owner</p><br><br><p>( .............................. )</p></div>
  </div>
</div></div>
@endsection
