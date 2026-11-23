@extends('layouts.tabler')
@section('title', 'Retur & Refund')
@section('content')
<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <div class="page-pretitle">
                Penjualan
            </div>
            <h2 class="page-title">
                Retur & Refund
            </h2>
        </div>
        <div class="col-auto ms-auto d-flex gap-2">
            <a
                href="{{ route('returns.index', array_merge(request()->query(), ['export' => 'csv'])) }}"
                class="btn btn-outline-success"
            >
                <i class="ti ti-download me-1"></i> Export CSV
            </a>
        </div>
    </div>
</div>
<div class="row g-3">
<div class="col-lg-4">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Catat Retur</h3>
        </div>
        <div class="card-body">
            <form
                method="POST"
                action="{{ route('returns.store') }}"
                enctype="multipart/form-data"
                onsubmit="return confirm('Simpan retur? Stok & kas disesuaikan otomatis.')"
            >
                @csrf
                <label class="form-label">Transaksi</label>
                <select name="sale_id" class="form-select" required>
                    <option value="">— pilih —</option>
                    @foreach($sales as $s)
                        <option value="{{ $s->id }}">{{ $s->invoice_no }} — Rp {{ number_format($s->total,0,',','.') }}</option>
                    @endforeach
                </select>
                <label class="form-label mt-2">Tipe</label>
                <select name="type" class="form-select">
                    <option value="retur">Retur (kembali stok)</option>
                    <option value="refund">Refund (uang kembali)</option>
                    <option value="tukar">Tukar barang</option>
                </select>
                <label class="form-label mt-2">Nominal refund (Rp)</label>
                <input
                    name="amount"
                    id="retur-amount"
                    type="number"
                    min="0"
                    value="0"
                    class="form-control"
                >
                <div
                    id="retur-sig-box"
                    class="border rounded p-2 mt-2 d-none"
                >
                    <div class="alert alert-warning py-2 px-2 mb-2">
                        <b>Uang kembali &gt; 0 wajib tanda tangan penerima</b>
                        — klik <i>Ambil Tanda Tangan</i> dulu sebelum Simpan.
                        Tukar barang nominal 0 dikecualikan.
                    </div>
                    <div
                        id="returSigPreviewWrap"
                        class="d-none"
                    >
                        <label class="form-label mb-1">Pratinjau TTD penerima</label>
                        <div>
                            <img
                                id="returSigPreview"
                                src=""
                                alt="TTD penerima retur"
                                style="max-height: 90px; background: #fff; border: 1px dashed #98a2b3; border-radius: 6px;"
                            >
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-2">
                        <button
                            type="button"
                            class="btn btn-outline-dark"
                            onclick="openReturSigModal()"
                        >
                            <i class="ti ti-signature me-1"></i>️ Ambil Tanda Tangan
                        </button>
                        <button
                            type="button"
                            class="btn btn-ghost-secondary d-none"
                            id="returSigClearBtn"
                            onclick="clearReturSig()"
                        >
                            Hapus
                        </button>
                    </div>
                </div>
                <input
                    type="hidden"
                    name="signature"
                    id="retur-sig-data"
                    value=""
                >
                <label class="form-label mt-2">Alasan</label>
                <textarea name="reason" class="form-control" rows="2"></textarea>
                <label class="form-label mt-2">Foto bukti (opsional)</label>
                <input
                    type="file"
                    name="bukti"
                    accept="image/*"
                    class="form-control"
                >
                <div class="form-text">
                    Foto kondisi barang / nota retur. Tersimpan di storage + path dicatat [BUKTI:path] pada alasan retur & kas keluar refund ini.
                </div>
                <div class="form-text">
                    TTD penerima wajib bila nominal &gt; 0 (disimpan di storage/signatures + path dicatat [SIG:path] pada alasan). Tukar nominal 0 tanpa TTD.
                </div>
                <div class="form-text">Stok otomatis dikembalikan (unit IMEI → tersedia, non-serial → qty +) + kas keluar + movement retur_jual.</div>
                <button class="btn btn-warning w-100 mt-2">Simpan Retur</button>
            </form>
        </div>
    </div>
</div>
<div class="col-lg-8"><div class="card"><div class="table-responsive"><table class="table table-hover mb-0">
<thead>
<tr>
<th>Waktu</th>
<th>Invoice</th>
<th>Tipe</th>
<th class="text-end">Nominal</th>
<th>Alasan</th>
<th class="d-print-none">Bukti</th>
</tr>
</thead>
<tbody>
@forelse($returns as $r)
<tr>
<td class="small">{{ $r->created_at->format('d/m/Y H:i') }}</td>
<td class="font-monospace">{{ $r->sale->invoice_no ?? '' }}</td>
<td>
<span class="badge bg-orange-lt">{{ $r->type }}</span>
</td>
<td class="text-end">{{ number_format($r->amount,0,',','.') }}</td>
<td class="small">
@php
$buktiRetur = null;
if (preg_match('/\[BUKTI:(.*?)\]/', $r->reason ?? '', $mRetur)) {
$buktiRetur = trim($mRetur[1]);
}
$sigRetur = null;
if (preg_match('/\[SIG:(.*?)\]/', $r->reason ?? '', $mSig)) {
$sigRetur = trim($mSig[1]);
}
$alasanBersih = trim(preg_replace('/\[(BUKTI|SIG):.*?\]/', '', $r->reason ?? ''));
@endphp
<div>{{ $alasanBersih ?: '-' }}</div>
@if($buktiRetur)
<a
href="{{ asset('storage/' . $buktiRetur) }}"
target="_blank"
class="d-inline-block mt-1"
>
<img
src="{{ asset('storage/' . $buktiRetur) }}"
alt="Bukti retur RTR-{{ $r->id }}"
style="height:48px;border-radius:4px;border:1px solid #ddd"
>
</a>
@endif
@if($sigRetur)
<div class="mt-1">
<a
href="{{ asset('storage/' . $sigRetur) }}"
target="_blank"
class="d-inline-block"
title="TTD penerima"
>
<img
src="{{ asset('storage/' . $sigRetur) }}"
alt="TTD penerima RTR-{{ $r->id }}"
style="height:48px;border-radius:4px;border:1px solid #ddd;background:#fff"
>
</a>
<span class="badge bg-dark-lt ms-1">bertanda tangan</span>
</div>
@endif
</td>
<td class="d-print-none">
<button
type="button"
class="btn btn-sm btn-outline-dark"
onclick="printStruk('retur-struk-{{ $r->id }}')"
>
<i class="ti ti-printer me-1"></i> Cetak
</button>
</td>
</tr>
@empty
<tr>
<td colspan="6">
<div class="empty py-3">
<div class="empty-icon"><i class="ti ti-arrow-back-up fs-1 text-secondary"></i></div>
<p class="empty-title">Belum ada retur</p>
<p class="empty-subtitle text-secondary">Catat retur lewat formulir di samping — stok kembali & kas keluar otomatis.</p>
</div>
</td>
</tr>
@endforelse
</tbody>
</table></div>
<div class="card-footer">{{ $returns->links() }}</div></div>

{{-- Area cetak bukti retur/refund per baris (tanpa route baru, pakai data baris + printStruk global). --}}
@foreach($returns as $r)
<div
id="retur-struk-{{ $r->id }}"
style="display:none"
>
<div style="font-family:monospace;font-size:12px;width:58mm;color:#000">
<div style="text-align:center;font-weight:bold;font-size:14px">
BUKTI {{ strtoupper($r->type) }}
</div>
<div style="text-align:center">
{{ $r->store->name ?? '' }}
</div>
<div style="text-align:center">
No. RTR-{{ $r->id }}
</div>
<hr>
<div>
Nota asal: {{ $r->sale->invoice_no ?? '-' }}
</div>
<div>
Tanggal retur: {{ $r->created_at->format('d/m/Y H:i') }}
</div>
<div>
Item: {{ $r->saleItem->description ?? 'Semua item nota asal' }}
</div>
<div>
Alasan: {{ trim(preg_replace('/\[(BUKTI|SIG):.*?\]/', '', $r->reason ?? '')) ?: '-' }}
</div>
@php
$buktiStruk = null;
if (preg_match('/\[BUKTI:(.*?)\]/', $r->reason ?? '', $mStruk)) {
$buktiStruk = trim($mStruk[1]);
}
$sigStruk = null;
if (preg_match('/\[SIG:(.*?)\]/', $r->reason ?? '', $mSigStruk)) {
$sigStruk = trim($mSigStruk[1]);
}
@endphp
@if($buktiStruk)
<div>
Foto bukti: {{ $buktiStruk }}
</div>
@endif
<div>
Kasir: {{ $r->user->name ?? '-' }}
</div>
<hr>
<div style="font-size:14px;font-weight:bold">
Jumlah kembali: Rp {{ number_format($r->amount, 0, ',', '.') }}
</div>
<hr>
<div style="display:flex;justify-content:space-between;margin-top:24px">
<div style="text-align:center">
Penerima dana,
@if($sigStruk)
<br>
<img
src="{{ asset('storage/' . $sigStruk) }}"
alt="TTD penerima"
style="max-height:60px;max-width:120px;background:#fff"
>
<br>
@endif
<br><br>
( .................... )
</div>
<div style="text-align:center">
Kasir,
<br><br><br>
( {{ $r->user->name ?? '....................' }} )
</div>
</div>
<div style="text-align:center;margin-top:8px">
Simpan bukti ini sebagai arsip toko.
</div>
</div>
</div>
@endforeach

<!-- Modal TTD penerima retur/refund (canvas JS murni, cermin pos/index, tanpa lib baru) -->
<div
    class="modal fade"
    id="returSigModal"
    tabindex="-1"
    aria-hidden="true"
>
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Tanda tangan penerima dana</h5>
                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Tutup"
                ></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning py-2 px-2 mb-2">
                    Penerima menyetujui penerimaan uang kembali.
                    TTD wajib bila nominal &gt; 0.
                </div>
                <canvas
                    id="returSigPad"
                    width="440"
                    height="220"
                    style="width: 100%; height: 220px; background: #fff; border: 1px solid #d1d5db; border-radius: 8px; touch-action: none; cursor: crosshair;"
                ></canvas>
                <div class="text-secondary small mt-1">Gambar dengan mouse / jari.</div>
            </div>
            <div class="modal-footer">
                <button
                    type="button"
                    class="btn btn-ghost-secondary"
                    onclick="clearReturSig()"
                >
                    Hapus
                </button>
                <button
                    type="button"
                    class="btn btn-primary"
                    onclick="saveReturSig()"
                >
                    Pakai Tanda Tangan
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ---- TTD PENERIMA RETUR/REFUND (canvas JS murni, cermin pos/index, tanpa lib baru) ----
// Wajib bila nominal amount > 0. Tukar nominal 0 otomatis bebas (box disembunyikan).
var returSigCanvas = null;
var returSigCtx = null;
var returSigDrawing = false;
var returSigHasDraw = false;
var returSigLast = null;
function returSigPos(e) {
    var r = returSigCanvas.getBoundingClientRect();
    var cx = (e.touches && e.touches[0]) ? e.touches[0].clientX : e.clientX;
    var cy = (e.touches && e.touches[0]) ? e.touches[0].clientY : e.clientY;
    return {
        x: (cx - r.left) * (returSigCanvas.width / r.width),
        y: (cy - r.top) * (returSigCanvas.height / r.height)
    };
}
function returSigSetup() {
    returSigCanvas = returSigCanvas || document.getElementById('returSigPad');
    if (!returSigCanvas || returSigCtx) return;
    returSigCtx = returSigCanvas.getContext('2d');
    returSigCtx.lineWidth = 2.5;
    returSigCtx.lineCap = 'round';
    returSigCtx.lineJoin = 'round';
    returSigCtx.strokeStyle = '#111';
}
function returSigStart(e) {
    returSigSetup();
    returSigDrawing = true;
    returSigHasDraw = true;
    returSigLast = returSigPos(e);
    if (e.cancelable) e.preventDefault();
}
function returSigMove(e) {
    if (!returSigDrawing) return;
    var p = returSigPos(e);
    returSigCtx.beginPath();
    returSigCtx.moveTo(returSigLast.x, returSigLast.y);
    returSigCtx.lineTo(p.x, p.y);
    returSigCtx.stroke();
    returSigLast = p;
    if (e.cancelable) e.preventDefault();
}
function returSigEnd() {
    returSigDrawing = false;
}
function returSigRequired() {
    var amt = Number(document.getElementById('retur-amount')?.value || 0);
    return amt > 0;
}
function syncReturSigUI() {
    var need = returSigRequired();
    var box = document.getElementById('retur-sig-box');
    var hint = document.getElementById('returSigPreviewWrap');
    var clearBtn = document.getElementById('returSigClearBtn');
    var sigInput = document.getElementById('retur-sig-data');
    var hasSig = !!(sigInput && sigInput.value);
    if (box) box.classList.toggle('d-none', !need);
    if (hint) hint.classList.toggle('d-none', !hasSig);
    if (clearBtn) clearBtn.classList.toggle('d-none', !hasSig);
}
function openReturSigModal() {
    returSigSetup();
    var m = document.getElementById('returSigModal');
    try {
        bootstrap.Modal.getOrCreateInstance(m).show();
    } catch (e) {
        m.classList.add('show');
        m.style.display = 'block';
    }
}
function closeReturSigModal() {
    var m = document.getElementById('returSigModal');
    try {
        var inst = bootstrap.Modal.getInstance(m);
        if (inst) inst.hide();
        else {
            m.classList.remove('show');
            m.style.display = 'none';
        }
    } catch (e) {
        m.classList.remove('show');
        m.style.display = 'none';
    }
}
function clearReturSig() {
    returSigSetup();
    returSigCtx.clearRect(0, 0, returSigCanvas.width, returSigCanvas.height);
    returSigHasDraw = false;
    var input = document.getElementById('retur-sig-data');
    if (input) input.value = '';
    var prev = document.getElementById('returSigPreview');
    if (prev) prev.src = '';
    syncReturSigUI();
}
function saveReturSig() {
    if (!returSigHasDraw) {
        alert('Coret dulu tanda tangannya.');
        return;
    }
    var url = returSigCanvas.toDataURL('image/png');
    var input = document.getElementById('retur-sig-data');
    if (input) input.value = url;
    var prev = document.getElementById('returSigPreview');
    if (prev) prev.src = url;
    closeReturSigModal();
    syncReturSigUI();
}
(function returSigInit() {
    returSigSetup();
    if (returSigCanvas) {
        returSigCanvas.addEventListener('mousedown', returSigStart);
        returSigCanvas.addEventListener('mousemove', returSigMove);
        window.addEventListener('mouseup', returSigEnd);
        returSigCanvas.addEventListener('touchstart', returSigStart, {passive: false});
        returSigCanvas.addEventListener('touchmove', returSigMove, {passive: false});
        returSigCanvas.addEventListener('touchend', returSigEnd);
    }
    document.getElementById('retur-amount')?.addEventListener('input', syncReturSigUI);
    syncReturSigUI();
})();
document.querySelector('form[action="{{ route('returns.store') }}"]')?.addEventListener('submit', function (e) {
    if (returSigRequired() && !document.getElementById('retur-sig-data').value) {
        e.preventDefault();
        syncReturSigUI();
        openReturSigModal();
        alert('Retur/refund dengan uang kembali wajib tanda tangan penerima dulu.');
        return false;
    }
});
</script>
@endsection
