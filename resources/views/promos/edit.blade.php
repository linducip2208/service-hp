@extends('layouts.tabler')
@section('title','Edit Promo')
@section('content')
<div class="card col-lg-7 mx-auto"><div class="card-header"><h3 class="card-title">Edit {{ $promo->code }}</h3></div>
<div class="card-body"><form method="POST" action="{{ route('promos.update',$promo) }}">@csrf @method('PUT')
<div class="mb-2"><label class="form-label">Toko</label><select name="store_id" class="form-select"><option value="">— Semua toko —</option>@foreach($stores as $s)<option value="{{ $s->id }}" {{ (int)$promo->store_id===$s->id?'selected':'' }}>{{ $s->name }}</option>@endforeach</select></div>
<div class="row"><div class="col-4 mb-2"><label class="form-label">Kode</label><input name="code" value="{{ $promo->code }}" class="form-control" required></div>
<div class="col-8 mb-2"><label class="form-label">Nama</label><input name="name" value="{{ $promo->name }}" class="form-control" required></div></div>
<div class="row"><div class="col-4 mb-2"><label class="form-label">Tipe</label><select name="type" id="promo-type" class="form-select">@foreach(['diskon_persen','diskon_rupiah','bundling','voucher','member'] as $t)<option value="{{ $t }}" {{ $promo->type==$t?'selected':'' }}>{{ $t }}</option>@endforeach</select></div>
<div class="col-4 mb-2"><label class="form-label">Nilai</label><input type="number" name="value" value="{{ $promo->value }}" class="form-control" min="0" required></div>
<div class="col-4 mb-2"><label class="form-label">Min. belanja</label><input type="number" name="min_belanja" value="{{ $promo->min_belanja }}" class="form-control" min="0"></div></div>
<div class="alert alert-info py-2 small" id="promo-hint"></div>
<script>
const PROMO_HINTS = {
  diskon_persen: ' <b>diskon_persen</b>: Nilai = persen (0–100). Contoh: 10 = potongan 10% dari subtotal. Berlaku bila min. belanja + quota + periode terpenuhi.',
  diskon_rupiah: ' <b>diskon_rupiah</b>: Nilai = rupiah. Contoh: 20000 = potongan Rp 20.000 (dicap maksimal subtotal). Berlaku bila min. belanja + quota + periode terpenuhi.',
  voucher: ' <b>voucher</b>: Nilai = nominal rupiah, sekali pakai per transaksi (satu kode untuk satu struk). Tetap hormati min. belanja + quota + tanggal.',
  member: ' <b>member</b>: Khusus pelanggan Silver (total belanja ≥ Rp 1 jt) / Gold (> Rp 5 jt). Nilai ≤100 dibaca persen, >100 dibaca rupiah. Tetap hormati min. belanja + quota + tanggal.',
  bundling: '<i class="ti ti-package me-1"></i> <b>bundling</b>: Nilai = persen diskon (0–100) yang HANYA berlaku bila keranjang berisi ≥2 produk berbeda. Kolom yang ada belum menyimpan pasangan produk A/B, jadi aturan pasangan spesifik (mis. "beli A gratis B") belum didukung — syarat minimal 2 item berbeda + pesan jelas bila tak terpenuhi.',
};
const _pt = document.getElementById('promo-type'), _ph = document.getElementById('promo-hint');
function _syncHint(){ _ph.innerHTML = PROMO_HINTS[_pt.value] || ''; }
_pt.addEventListener('change', _syncHint); _syncHint();
</script>
<div class="row"><div class="col-4 mb-2"><label class="form-label">Mulai</label><input type="date" name="start_date" value="{{ $promo->start_date?->format('Y-m-d') }}" class="form-control"></div>
<div class="col-4 mb-2"><label class="form-label">Berakhir</label><input type="date" name="end_date" value="{{ $promo->end_date?->format('Y-m-d') }}" class="form-control"></div>
<div class="col-4 mb-2"><label class="form-label">Quota</label><input type="number" name="quota" value="{{ $promo->quota }}" class="form-control" min="0"></div></div>
<label class="form-check"><input type="checkbox" name="is_active" value="1" class="form-check-input" {{ $promo->is_active?'checked':'' }}><span class="form-check-label">Aktif</span></label>
<div class="mt-2"><button class="btn btn-primary">Simpan</button></div></form></div></div>
@endsection
