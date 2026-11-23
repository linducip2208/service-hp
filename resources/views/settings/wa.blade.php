@extends('layouts.tabler')
@section('title','Pengaturan WhatsApp')
@section('content')
<div class="page-header"><h2 class="page-title">Notifikasi WhatsApp <a href="{{ route('help') }}#wa" class="btn btn-sm btn-outline-secondary ms-2" title="Bantuan WA">?</a></h2></div>
<div class="row row-cards mt-2">
<div class="col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Gateway</h3></div><div class="card-body">
<p class="text-secondary">Mendukung Fonnte / Wablas / gateway generik berbasis HTTP POST (<code>target/phone + message</code>, header <code>Authorization: token</code>). Tanpa gateway, pesan dicatat di log (mode antre).</p>
<form method="POST" action="{{ route('settings.wa.save') }}">@csrf
<div class="mb-3"><label class="form-label">Gateway URL</label><input name="wa_url" class="form-control" value="{{ $cfg['WA_GATEWAY_URL'] ?? '' }}" placeholder="https://api.fonnte.com/send"></div>
<div class="mb-3"><label class="form-label">Token</label><input name="wa_token" class="form-control" value="{{ $cfg['WA_GATEWAY_TOKEN'] ?? '' }}"></div>
<div class="mb-3"><label class="form-label">Nama Pengirim</label><input name="wa_sender" class="form-control" value="{{ $cfg['WA_SENDER'] ?? 'ServiceHP' }}"></div>
<button class="btn btn-primary">Simpan</button>
</form>
<hr><h4>Test kirim</h4>
<form method="POST" action="{{ route('settings.wa.test') }}">@csrf
<div class="mb-2"><label class="form-label">No HP (08...)</label><input name="phone" class="form-control" required></div>
<div class="mb-2"><label class="form-label">Pesan</label><textarea name="message" class="form-control" required>Halo, ini test notifikasi ServiceHP <i class="ti ti-check me-1"></i></textarea></div>
<button class="btn btn-outline-primary">Kirim Test</button>
</form>
</div></div></div>
<div class="col-lg-6">
<div class="card"><div class="card-header"><h3 class="card-title">Template notifikasi</h3></div><div class="card-body">
<p class="text-secondary small">Placeholder: <code>{nama}</code> <code>{tiket}</code> <code>{status}</code> <code>{total}</code> + <code>{sisa}</code> <code>{jadwal}</code> <code>{device}</code> <code>{track}</code>. Kosongkan untuk memakai teks bawaan.</p>
<form method="POST" action="{{ route('settings.wa.save') }}">@csrf
<input type="hidden" name="wa_url" value="{{ $cfg['WA_GATEWAY_URL'] ?? '' }}">
<input type="hidden" name="wa_token" value="{{ $cfg['WA_GATEWAY_TOKEN'] ?? '' }}">
<input type="hidden" name="wa_sender" value="{{ $cfg['WA_SENDER'] ?? 'ServiceHP' }}">
<div class="mb-2"><label class="form-label">Service diterima</label><textarea name="tpl_service_diterima" class="form-control" rows="2">{{ old('tpl_service_diterima', $tpls['service_diterima'] ?? '') }}</textarea></div>
<div class="mb-2"><label class="form-label">Service siap diambil</label><textarea name="tpl_service_siap" class="form-control" rows="2">{{ old('tpl_service_siap', $tpls['service_siap_diambil'] ?? '') }}</textarea></div>
<div class="mb-2"><label class="form-label">Service diserahkan</label><textarea name="tpl_service_diserahkan" class="form-control" rows="2">{{ old('tpl_service_diserahkan', $tpls['service_diserahkan'] ?? '') }}</textarea></div>
<div class="mb-2"><label class="form-label">Booking konfirmasi / pengingat</label><textarea name="tpl_booking" class="form-control" rows="2">{{ old('tpl_booking', $tpls['booking_konfirmasi'] ?? '') }}</textarea></div>
<div class="mb-2"><label class="form-label">Hutang jatuh tempo</label><textarea name="tpl_hutang" class="form-control" rows="2">{{ old('tpl_hutang', $tpls['hutang_jatuh_tempo'] ?? '') }}</textarea></div>
<button class="btn btn-primary">Simpan template</button>
</form>
</div></div>
</div>
</div>
<div class="card mt-3"><div class="card-header"><h3 class="card-title">Log 20 terakhir</h3></div>
<div class="table-responsive"><table class="table card-table"><thead><tr><th>Waktu</th><th>Tujuan</th><th>Status</th><th>Pesan</th><th class="text-end">Aksi</th></tr></thead><tbody>
@forelse($logs as $l)<tr><td class="text-secondary">{{ \Carbon\Carbon::parse($l->created_at)->format('d/m/Y H:i') }}</td><td>{{ $l->phone }}</td><td><span class="badge bg-{{ $l->status=='terkirim'?'green':($l->status=='gagal'?'red':'yellow') }}-lt">{{ $l->status }}</span></td><td class="text-secondary">{{ \Str::limit($l->message,60) }}</td>
<td class="text-end">@if(in_array($l->status,['gagal','antri']))<form method="POST" action="{{ route('settings.wa.retry',$l->id) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-warning" title="Kirim ulang via gateway aktif">↻ Kirim ulang</button></form>@else<span class="text-secondary small">—</span>@endif</td></tr>
@empty<tr><td colspan="5" class="text-center text-secondary">Belum ada log.</td></tr>@endforelse
</tbody></table></div></div>
@endsection
