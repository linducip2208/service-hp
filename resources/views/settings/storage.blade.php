@extends('layouts.tabler')
@section('title','File Yatim (Storage)')
@section('content')
<div class="page-header"><div class="row align-items-center"><div class="col">
  <div class="page-pretitle">Master • Khusus superadmin/owner</div>
  <h2 class="page-title"><i class="ti ti-folder me-1"></i> File Yatim (Storage)</h2>
</div>
<div class="col-auto ms-auto"><a href="{{ route('backup.index') }}" class="btn btn-outline-secondary"><i class="ti ti-device-floppy me-1"></i> Backup Data</a></div>
</div></div>

<div class="alert alert-warning mt-3"><i class="ti ti-alert-triangle me-1"></i> Halaman ini membandingkan <b>file di <code>storage/app/public/*</code></b> dengan <b>referensi di database</b>.
Hanya file yang <b>tidak dirujuk di mana pun (= yatim)</b> yang ditampilkan & boleh dihapus.
<b>.gitignore / dotfile tidak pernah dipindai dan tidak bisa dihapus dari sini.</b>
Hapus hanya yang sudah yakin — aksi tidak bisa dibatalkan.</div>

<div class="card mt-2"><div class="card-header"><h3 class="card-title">Ringkasan per Folder ({{ count($files) }} file dipindai • {{ $usedCount }} terpakai • {{ count($orphans) }} yatim • {{ $orphanBytesH }})</h3></div>
  <div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr>
    <th>Folder</th><th>Total File</th><th>Yatim</th><th>Ukuran Yatim</th><th>Aturan Kecocokan (dirujuk bila…)</th>
  </tr></thead><tbody>
  @foreach($perFolder as $dir => $s)
  <tr>
    <td><code>{{ $dir }}</code></td>
    <td>{{ $s['total'] }}</td>
    <td><span class="badge bg-{{ $s['orphan'] ? 'red' : 'green' }}-lt">{{ $s['orphan'] }}</span></td>
    <td>{{ $s['orphan_size_h'] }}</td>
    <td class="text-secondary small">
      @if($dir === 'service') kolom JSON <code>service_tickets.photos</code> (termasuk <code>service/after/*</code>)
      @elseif($dir === 'signatures') marker <code>[SIG:path]</code> di <code>service_logs.note</code> / <code>sales.note</code>
      @elseif(in_array($dir, ['bukti-kas','bukti-pengeluaran'])) marker <code>[BUKTI:path]</code> di <code>cash_transactions.description</code> / <code>expenses.description</code>
      @elseif($dir === 'bukti-pembayaran') marker <code>[BUKTI:path]</code> di <code>sales.note</code> / <code>sale_payments.note</code>
      @elseif($dir === 'nota-supplier') marker <code>[BUKTI:path]</code> di <code>purchases.note</code>
      @elseif($dir === 'products') kolom <code>products.photo</code>
      @elseif(in_array($dir, ['consignment','secondhand'])) marker <code>[FOTO:path]</code> di <code>stock_movements.note</code>
      @elseif($dir === 'tradein') marker <code>[FOTO:path]</code> di <code>trade_ins.condition_note</code>
      @elseif($dir === 'logo') tercantum di <code>branding.json</code> atau <code>logo/{id-toko}.png</code> milik toko aktif
      @endif
    </td>
  </tr>
  @endforeach
  </tbody></table></div>
</div>

<form method="POST" action="{{ route('storage.destroy') }}" id="orphanForm">
@csrf @method('DELETE')
<div class="card mt-3"><div class="card-header"><h3 class="card-title">Kandidat Yatim ({{ count($orphans) }})</h3>
  <div class="ms-auto d-flex gap-2 align-items-center">
    <label class="form-check mb-0"><input type="checkbox" class="form-check-input" id="checkAll"> <span class="form-check-label">Centang semua</span></label>
    <label class="form-check mb-0"><input type="checkbox" class="form-check-input" name="confirm" value="1" required> <span class="form-check-label"><b>Saya yakin, hapus yang dicentang</b></span></label>
    <button class="btn btn-danger" id="btnDelete"><i class="ti ti-trash me-1"></i> Hapus Terpilih</button>
  </div>
</div>
  <div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr>
    <th style="width:36px"></th><th>File</th><th>Folder</th><th>Ukuran</th><th>Terakhir diubah</th>
  </tr></thead><tbody>
  @forelse($orphans as $o)
  <tr>
    <td><input type="checkbox" class="form-check-input orphan-check" name="files[]" value="{{ $o['rel'] }}"></td>
    <td><code>{{ $o['rel'] }}</code><br><a href="{{ asset('storage/' . $o['rel']) }}" target="_blank" class="small">pratinjau</a></td>
    <td><span class="badge bg-azure-lt">{{ $o['top'] }}</span></td>
    <td>{{ $o['size_h'] }}</td>
    <td>{{ $o['date'] }}</td>
  </tr>
  @empty
  <tr><td colspan="5" class="text-center text-secondary py-4"> Tidak ada file yatim. Semua file storage masih dirujuk database.</td></tr>
  @endforelse
  </tbody></table></div>
</div>
</form>

<script>
(function(){
  var all = document.getElementById('checkAll');
  if (all) all.addEventListener('change', function(){
    document.querySelectorAll('.orphan-check').forEach(function(c){ c.checked = all.checked; });
  });
  var form = document.getElementById('orphanForm');
  if (form) form.addEventListener('submit', function(e){
    var n = document.querySelectorAll('.orphan-check:checked').length;
    if (!n) { e.preventDefault(); alert('Pilih dulu minimal 1 file.'); return; }
    if (!confirm('HAPUS ' + n + ' file yatim yang dicentang? Aksi tidak bisa dibatalkan.')) e.preventDefault();
  });
})();
</script>
@endsection
