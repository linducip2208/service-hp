@extends('layouts.tabler')
@section('title','Printer & Cetak')
@section('content')
<div class="page-header"><div class="row align-items-center"><div class="col">
  <div class="page-pretitle">Pengaturan • Berlaku semua toko</div>
  <h2 class="page-title"><i class="ti ti-printer me-1"></i> Printer & Pusat Cetak</h2>
  <div class="mt-1"><a href="{{ route('help') }}#cetak" class="btn btn-sm btn-outline-secondary" title="Bantuan Cetak">? Bantuan cetak</a></div>
</div></div></div>

<div class="row row-cards mt-2">
  {{-- DAFTAR PRINTER LAN --}}
  <div class="col-lg-7">
    <div class="card"><div class="card-header"><h3 class="card-title"><i class="ti ti-wifi me-1"></i> Printer WiFi / LAN (cetak langsung dari server)</h3></div>
      <div class="table-responsive"><table class="table card-table table-vcenter"><thead><tr><th>Printer</th><th>Alamat</th><th>Kertas</th><th>Status</th><th class="text-end">Aksi</th></tr></thead><tbody>
      @forelse($printers as $p)
      <tr>
        <td><b>{{ $p['name'] }}</b><br><small class="text-secondary">{{ $p['id'] }}</small></td>
        <td><code>{{ $p['ip'] }}:{{ $p['port'] }}</code></td>
        <td><span class="badge bg-blue-lt">{{ $p['width'] }}mm</span></td>
        <td>@if($p['is_default'])<span class="badge bg-green-lt">Default</span>@endif</td>
        <td class="text-end"><div class="btn-list justify-content-end">
          <form method="POST" action="{{ route('settings.printers.test',$p['id']) }}" class="d-inline" data-loading>@csrf<button class="btn btn-sm btn-outline-primary" type="submit">Test</button></form>
          <form method="POST" action="{{ route('settings.printers.drawer',$p['id']) }}" class="d-inline" data-loading title="Kirim pulsa ESC p (5 byte) untuk membuka laci kas">@csrf<button class="btn btn-sm btn-outline-warning" type="submit">Test Buka Laci</button></form>
          @unless($p['is_default'])<form method="POST" action="{{ route('settings.printers.default',$p['id']) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-secondary">Default</button></form>@endunless
          <form method="POST" action="{{ route('settings.printers.destroy',$p['id']) }}" class="d-inline" onsubmit="return confirm('Hapus printer ini?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Hapus</button></form>
        </div></td>
      </tr>
      @empty<tr><td colspan="5" class="text-center text-secondary py-4">Belum ada printer jaringan.<br><small>Printer USB & printer HP tetap bisa dipakai lewat tombol browser/Bluetooth di halaman cetak.</small></td></tr>@endforelse
      </tbody></table></div>
    </div>
    <div class="card mt-3"><div class="card-header"><h3 class="card-title"><i class="ti ti-plus me-1"></i> Tambah printer WiFi/LAN</h3></div><div class="card-body">
      <form method="POST" action="{{ route('settings.printers.store') }}" class="row g-2" data-loading>@csrf
        <div class="col-md-4"><label class="form-label">Nama (cth: Kasir-80)</label><input name="name" class="form-control" required></div>
        <div class="col-md-4"><label class="form-label">IP Printer</label><input name="ip" class="form-control" placeholder="192.168.1.50" required></div>
        <div class="col-md-2"><label class="form-label">Port</label><input name="port" type="number" class="form-control" value="9100"></div>
        <div class="col-md-2"><label class="form-label">Kertas</label><select name="width" class="form-select"><option value="58">58mm</option><option value="80" selected>80mm</option></select></div>
        <div class="col-12"><button class="btn btn-primary">Simpan & Test</button>
        <span class="text-secondary small ms-2">Aktifkan mode RAW/Network di printer, pastikan satu jaringan dengan server.</span></div>
      </form>
    </div></div>
  </div>

  {{-- PANDUAN KOMPATIBILITAS --}}
  <div class="col-lg-5">
    <div class="card"><div class="card-header"><h3 class="card-title"><i class="ti ti-check me-1"></i> Semua printer didukung</h3></div>
      <div class="list-group list-group-flush">
        <div class="list-group-item"><b><i class="ti ti-printer me-1"></i> Printer apa pun (USB, inkjet, laser)</b><div class="text-secondary small">Tombol <i>Struk 58/80mm</i> & <i>Invoice A4</i> memakai cetak browser — instal driver seperti biasa lalu pilih printernya di dialog cetak. Tersedia juga <i>Simpan sebagai PDF</i>.</div></div>
        <div class="list-group-item"><b><i class="ti ti-wifi me-1"></i> Thermal Bluetooth (XPrinter, Epson TM, iWare…)</b><div class="text-secondary small">Tombol <i>Bluetooth</i> di halaman cetak (Chrome/Edge HP & PC). Tanpa aplikasi tambahan; pairing sekali, cetak ESC/POS + QR otomatis.</div></div>
        <div class="list-group-item"><b><i class="ti ti-plug me-1"></i> Thermal USB (PC kasir)</b><div class="text-secondary small">Tombol <i>USB</i> (Chrome desktop) mengirim ESC/POS langsung — ideal untuk XPrinter USB di meja kasir.</div></div>
        <div class="list-group-item"><b><i class="ti ti-wifi me-1"></i> Thermal WiFi/LAN</b><div class="text-secondary small">Daftarkan di tabel samping, lalu cetak satu klik dari HP/kasir mana pun. Cocok untuk dapur struk / printer gudang.</div></div>
        <div class="list-group-item"><b><i class="ti ti-photo me-1"></i> PNG / Bagikan</b><div class="text-secondary small">Simpan struk sebagai gambar untuk aplikasi RawBT, arsip, atau kirim via WhatsApp ke pelanggan.</div></div>
      </div>
    </div>
    <div class="alert alert-info mt-3"><i class="ti ti-bulb me-1"></i> <b>Tips kasir:</b> di HP Android + printer Bluetooth, cara tercepat adalah tombol <i>Bluetooth</i>. Untuk struk formal/nota servis ke pelanggan kantor, gunakan <i>Invoice A4</i> → printer inkjet/laser atau PDF.</div>
  </div>
</div>

{{-- BRANDING STRUK PER TOKO --}}
<div class="card mt-3"><div class="card-header"><h3 class="card-title"><i class="ti ti-palette me-1"></i> Branding Struk per Toko</h3><span class="card-subtitle ms-2">Logo + header/footer custom di struk 58/80, invoice A4 & tiket service</span></div>
<div class="card-body">
  <form method="POST" action="{{ route('settings.printers.branding') }}" enctype="multipart/form-data" class="row g-3" data-loading>@csrf
    <div class="col-md-3">
      <label class="form-label">Toko</label>
      <select name="store_id" class="form-select" onchange="location.href='?store='+this.value">
        @foreach($stores as $s)<option value="{{ $s->id }}" {{ (int)($activeStoreId ?? 0)===(int)$s->id ? 'selected' : '' }}>{{ $s->name }}</option>@endforeach
      </select>
      @if($logoUrl)<div class="mt-2"><img src="{{ asset($logoUrl) }}" alt="Logo" style="max-height:70px;max-width:100%" class="border rounded bg-white p-1"><div class="form-check mt-1"><input type="checkbox" name="remove_logo" value="1" class="form-check-input" id="rmLogo"><label class="form-check-label" for="rmLogo">Hapus logo</label></div></div>@endif
    </div>
    <div class="col-md-3">
      <label class="form-label">Logo toko (PNG/JPG, maks 1MB)</label>
      <input type="file" name="logo" accept="image/png,image/jpeg" class="form-control">
      <small class="text-secondary">Disimpan sebagai <code>storage/app/public/logo/{toko}.png</code>. Tampil di browser/PDF; di thermal ESC/POS tetap header teks.</small>
    </div>
    <div class="col-md-3">
      <label class="form-label">Header custom (ganti alamat/telp)</label>
      <textarea name="header_text" rows="3" class="form-control" placeholder="cth:&#10;Jl. Merdeka No.10 — 0812xxxx&#10;Buka 09.00–21.00">{{ $branding['header'] ?? '' }}</textarea>
    </div>
    <div class="col-md-3">
      <label class="form-label">Footer custom (promo/garansi)</label>
      <textarea name="footer_text" rows="3" class="form-control" placeholder="cth:&#10;Terima kasih  Promo servis 10% s/d akhir bulan&#10;Garansi 30 hari dgn struk ini">{{ $branding['footer'] ?? '' }}</textarea>
    </div>
    <div class="col-12 d-flex flex-wrap align-items-center gap-3"><button class="btn btn-primary">Simpan Branding</button>
    <div class="form-check form-switch mb-0">
      <input type="checkbox" name="auto_print" value="1" class="form-check-input" id="autoPrint" {{ !empty($branding['auto_print']) ? 'checked' : '' }}>
      <label class="form-check-label" for="autoPrint"><i class="ti ti-printer me-1"></i> Cetak otomatis setelah simpan <small class="text-secondary">(struk langsung tercetak saat halaman cetak dibuka)</small></label>
    </div>
    <div class="form-check form-switch mb-0">
      <input type="checkbox" name="auto_drawer" value="1" class="form-check-input" id="autoDrawer" {{ !empty($branding['auto_drawer']) ? 'checked' : '' }}>
      <label class="form-check-label" for="autoDrawer"><i class="ti ti-archive me-1"></i> Buka laci otomatis setelah bayar tunai <small class="text-secondary">(drawer-kick via BT/USB yang terakhir dipakai — tanpa pairing ulang; dilewati diam-diam bila belum connect)</small></label>
    </div>
    <span class="text-secondary small ms-2">Kosongkan header/footer untuk kembali ke teks default.</span></div>
  </form>
</div></div>
@endsection
