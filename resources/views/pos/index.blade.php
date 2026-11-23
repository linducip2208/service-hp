@extends('layouts.tabler')
@section('title', 'POS Kasir')
@section('content')
<style>
  #btn-kasir-mode {
    position: fixed;
    right: 16px;
    bottom: 16px;
    z-index: 1080;
    border-radius: 999px;
    padding: .8rem 1.2rem;
    font-size: 1.05rem;
    font-weight: 700;
    box-shadow: 0 8px 24px rgba(0, 0, 0, .25);
  }
  body.kasir-mode header.navbar {
    display: none !important;
  }
  body.kasir-mode .page-header .page-pretitle,
  body.kasir-mode .page-header .text-secondary {
    display: none !important;
  }
  body.kasir-mode {
    font-size: 18px;
  }
  body.kasir-mode .page-header .page-title {
    font-size: 1.6rem;
  }
  body.kasir-mode .btn {
    min-height: 52px;
    font-size: 1.15rem;
  }
  body.kasir-mode .btn.btn-sm {
    min-height: 46px;
    font-size: 1.05rem;
  }
  body.kasir-mode .form-control,
  body.kasir-mode .form-select {
    min-height: 52px;
    font-size: 1.2rem;
  }
  body.kasir-mode #scan {
    min-height: 62px;
    font-size: 1.35rem;
  }
  body.kasir-mode .table {
    font-size: 1.08rem;
  }
  body.kasir-mode #t-tot {
    font-size: 2rem;
  }
  body.kasir-mode #t-sub {
    font-size: 1.5rem;
  }
  body.kasir-mode .card-title {
    font-size: 1.25rem;
  }
  /* Corporate POS: ringkas, tombol aksi sejajar */
  .pos-actions .btn { min-height: 44px; font-weight: 600; }
  .pos-total-panel { position: sticky; top: 12px; }
  #scan:focus { border-color: var(--corp-accent); box-shadow: 0 0 0 3px rgba(15,95,208,.15); }
  .pos-pay-group .btn { font-weight: 600; }
</style>
<button
  type="button"
  id="btn-kasir-mode"
  class="btn btn-dark no-print"
  title="Mode Kasir: layar penuh + tampilan lebih besar (tersimpan otomatis)"
>
  <i class="ti ti-maximize me-1"></i>Mode Kasir
</button>
<div class="page-header d-print-none"><div class="row align-items-center">
  <div class="col"><div class="page-pretitle">Penjualan · Kasir</div><h2 class="page-title">POS — {{ $store->name ?? '' }}</h2>
  <div class="text-secondary">Scan barcode / ketik SKU / IMEI lalu Enter. Stok menipis &amp; habis ditandai otomatis.</div></div>
  <div class="col-auto pos-actions d-flex flex-wrap gap-2">
    <a href="{{ route('sales.closing') }}" class="btn btn-outline-warning"><i class="ti ti-lock me-1"></i>Tutup Kasir</a>
    <a href="{{ route('sales.index') }}" class="btn btn-outline-secondary"><i class="ti ti-history me-1"></i>Riwayat</a>
    @if(!empty($lastPrintSale))
      <a
        href="{{ route('pos.print', $lastPrintSale->id) }}"
        class="btn btn-outline-primary"
        title="Cetak ulang struk terakhir ({{ $lastPrintSale->invoice_no }}) — session dulu, fallback transaksi terakhir Anda hari ini"
      >
        <i class="ti ti-printer me-1"></i>Cetak Ulang
        <span class="small text-secondary d-block">
          {{ $lastPrintSale->invoice_no }}
        </span>
      </a>
    @endif
    <a href="{{ route('help') }}#pos" class="btn btn-outline-info" title="Bantuan POS"><i class="ti ti-help"></i></a>
  </div>
</div></div>

@if(session('success'))<div class="alert alert-success mt-2">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger mt-2">{{ session('error') }}</div>@endif
@if($errors->any())<div class="alert alert-danger mt-2">{{ $errors->first() }}</div>@endif

@if(!empty($repeatFrom))
<div class="alert alert-info mt-2">
    <div>
        <i class="ti ti-repeat me-1"></i><b>Ulangi transaksi {{ $repeatFrom->invoice_no }}</b> sebagai transaksi baru
        ({{ count($resumeCart ?? []) }} item dimuat, tanpa pembayaran lama).
    </div>
    @if(!empty($repeatNotices))
        <ul class="mb-0 mt-1 ps-3 small">
            @foreach($repeatNotices as $n)
                <li>{{ $n }}</li>
            @endforeach
        </ul>
    @endif
</div>
@endif

@if($lowCount > 0)
<div class="alert alert-warning mt-2"><i class="ti ti-alert-triangle me-1"></i>{{ $lowCount }} produk stok menipis / habis. Cek badge di daftar produk &amp; lakukan pembelian/pindah stok.</div>
@endif

@if(!empty($favorits) && $favorits->count())
<div class="card mt-2 border-primary"><div class="card-header"><h3 class="card-title"><i class="ti ti-star me-1"></i>Favorit 30 hari terakhir — 1 klik tambah ke keranjang</h3></div>
  <div class="card-body d-flex flex-wrap gap-2">
    @foreach($favorits as $f)
      @if($f['serialized'])
        <a href="{{ route('pos.index', ['q' => $f['name']]) }}" class="btn btn-outline-warning" title="Produk IMEI — pilih unit di tabel bawah (terjual {{ $f['terjual'] }})"><i class="ti ti-star me-1"></i>{{ Str::limit($f['name'], 22) }} <span class="badge bg-yellow-lt ms-1">{{ $f['terjual'] }}× • IMEI</span></a>
      @elseif($f['stock'] <= 0)
        <button class="btn btn-outline-secondary" disabled title="Stok habis"><i class="ti ti-star me-1"></i>{{ Str::limit($f['name'], 22) }} <span class="badge bg-red-lt ms-1">Habis</span></button>
      @else
        <button class="btn btn-outline-primary" onclick='addToCart({{ $f["product_id"] }}, @json($f["name"]), {{ $f["price"] }}, null, {{ $f["stock"] }})' title="Terjual {{ $f['terjual'] }} • stok {{ $f['stock'] }} • Rp {{ number_format($f['price'],0,',','.') }}"><i class="ti ti-star me-1"></i>{{ Str::limit($f['name'], 22) }} <span class="badge bg-blue-lt ms-1">{{ $f['terjual'] }}×</span></button>
      @endif
    @endforeach
  </div>
</div>
@endif

@if($heldSlots->count())
<div class="card mt-2 border-yellow"><div class="card-header"><h3 class="card-title"><i class="ti ti-pause me-1"></i>Struk ditahan ({{ $heldSlots->count() }})</h3></div>
  <div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>Slot</th><th>Pelanggan</th><th>Item</th><th>Ditahan</th><th></th></tr></thead>
    <tbody>@foreach($heldSlots as $h)
      <tr><td class="font-monospace">{{ $h['key'] }}</td><td>{{ $h['customer_name'] }}</td>
      <td>{{ count($h['items'] ?? []) }} item</td><td class="small text-secondary">{{ $h['created_at'] }}</td>
      <td class="text-end">
        <a href="{{ route('pos.index', ['resume' => $h['key']]) }}" class="btn btn-sm btn-success"><i class="ti ti-player-play me-1"></i>Lanjutkan</a>
        <a href="{{ route('pos.index', ['drop_held' => $h['key']]) }}" class="btn btn-sm btn-outline-danger" onclick="return confirm('Hapus struk ditahan ini?')">Hapus</a>
      </td></tr>
    @endforeach</tbody>
  </table></div>
</div>
@endif

<div class="row g-3 mt-1">
  <div class="col-lg-7">
    <div class="card mb-3"><div class="card-body">
      <div class="input-group input-group-lg">
        <span class="input-group-text"><i class="ti ti-scan"></i></span>
        <input id="scan" class="form-control form-control-lg" placeholder="Scan barcode / ketik SKU / IMEI lalu Enter…" autocomplete="off" autofocus>
        <button class="btn btn-primary btn-lg" id="btn-cari" type="button">Cari</button>
      </div>
      <div class="form-text d-flex justify-content-between align-items-center gap-2">
        <span>Fokus otomatis kembali ke kolom ini setelah tiap tambah item.</span>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-beep" title="Bunyi beep tiap scan (tersimpan otomatis)"><i class="ti ti-volume me-1"></i>Bunyi: ON</button>
      </div>
    </div></div>
    <div class="card"><div class="card-header"><h3 class="card-title">Produk tersedia</h3></div>
      <div class="table-responsive"><table class="table table-sm table-hover mb-0" id="tbl-produk">
        <thead><tr><th>Produk</th><th>SKU/Barcode</th><th class="text-end">Harga</th><th class="text-end">Stok</th><th></th></tr></thead>
        <tbody>
        @foreach($stocks as $s)
          @php $min = (int) ($s->min_stock ?? $s->product->min_stock ?? 5); $isOut = (int) $s->qty <= 0; $isLow = !$isOut && (int) $s->qty <= $min; @endphp
          <tr data-name="{{ strtolower($s->product->name.' '.$s->product->sku.' '.($s->product->barcode??'')) }}" class="{{ $isOut ? 'table-danger' : ($isLow ? 'table-warning' : '') }}">
            <td>{{ $s->product->name }}<div class="text-secondary small">{{ $s->product->brand->name ?? '' }} • {{ $s->product->type }}</div>
              @if($s->product->is_serialized)<span class="badge bg-blue-lt">Wajib IMEI</span>@endif
              @if($isOut)<span class="badge bg-red-lt">HABIS</span>@elseif($isLow)<span class="badge bg-yellow-lt">Menipis ≤ {{ $min }}</span>@endif
            </td>
            <td class="small">{{ $s->product->sku }}<br><span class="text-secondary">{{ $s->product->barcode }}</span></td>
            <td class="text-end">Rp {{ number_format($s->product->selling_price,0,',','.') }}</td>
            <td class="text-end"><b class="{{ $isOut ? 'text-danger' : ($isLow ? 'text-warning' : '') }}">{{ $s->qty }}</b></td>
            <td>@if($isOut)
              <button class="btn btn-sm btn-outline-secondary" disabled title="Stok habis">Kosong</button>
            @else
              <button class="btn btn-sm btn-outline-primary" onclick='addToCart({{ $s->product->id }}, @json($s->product->name), {{ $s->product->selling_price }}, null, {{ $s->qty }})'>+ Keranjang</button>
            @endif</td>
          </tr>
        @endforeach
        </tbody>
      </table></div>
    </div>
    @if($units->count())
    <div class="card mt-3"><div class="card-header"><h3 class="card-title">Unit IMEI tersedia (serialized)</h3><span class="card-subtitle ms-2">Hanya unit berstatus tersedia — unit terjual otomatis hilang dari sini</span></div>
      <div class="table-responsive"><table class="table table-sm table-hover mb-0">
        <thead><tr><th>IMEI</th><th>Produk</th><th class="text-end">Harga</th><th></th></tr></thead>
        <tbody>@foreach($units as $u)
          <tr data-name="{{ strtolower(($u->imei??'').' '.$u->product->name) }}">
            <td class="font-monospace">{{ $u->imei ?: '-' }}<div class="text-secondary small">{{ $u->condition }}</div>
              <span class="badge bg-green-lt">Tersedia</span></td>
            <td>{{ $u->product->name }}</td>
            <td class="text-end">Rp {{ number_format($u->selling_price,0,',','.') }}</td>
            <td><button class="btn btn-sm btn-outline-success" onclick='addToCart({{ $u->product_id }}, @json($u->product->name." (".($u->imei??"unit").")"), {{ $u->selling_price }}, {{ $u->id }}, 1)'>+ Keranjang</button></td>
          </tr>
        @endforeach</tbody>
      </table></div>
    </div>
    @endif
    <div class="card mt-3"><div class="card-header"><h3 class="card-title"><i class="ti ti-keyboard me-1"></i>Shortcut kasir</h3></div>
      <div class="card-body small">
        <span class="badge bg-secondary-lt me-1">F2</span> fokus scan
        <span class="badge bg-secondary-lt me-1 ms-2">F4</span> bayar tunai
        <span class="badge bg-secondary-lt me-1 ms-2">F9</span> tahan struk
        <span class="badge bg-secondary-lt me-1 ms-2">Esc</span> reset keranjang
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <form method="POST" action="{{ route('pos.store') }}" id="form-pos" data-loading>@csrf
      <input type="hidden" name="items_json" id="items_json">
      <input type="hidden" name="payments_json" id="payments_json">
      <input type="hidden" name="signature" id="sig-data" value="">
      <input type="hidden" name="hold_cart" id="hold_cart" value="">
      <div class="card"><div class="card-header"><h3 class="card-title">Keranjang</h3>
        @if(!empty($resumeName))<span class="badge bg-green-lt ms-2">Lanjutan: {{ $resumeName }}</span>@endif</div>
        <div class="card-body p-2">
          <table class="table table-sm mb-0"><thead><tr><th>Item</th><th width="70">Qty</th><th width="130">Harga</th><th></th></tr></thead>
          <tbody id="cart-body"><tr id="cart-empty"><td colspan="4" class="text-center text-secondary">Keranjang kosong</td></tr></tbody></table>
        </div>
        <div class="card-body border-top">
          <div class="row g-2">
            <div class="col-6"><label class="form-label d-flex justify-content-between align-items-center">Pelanggan
              <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2" data-bs-toggle="modal" data-bs-target="#modal-customer" title="Tambah pelanggan baru tanpa keluar POS">+ Baru</button></label>
              <select name="customer_id" id="customer_id" class="form-select" onchange="syncMemberPromo(true);syncDebtWarning(true);syncWaOptIn()"><option value="">— Umum —</option>
              @foreach($customers as $c)<option value="{{ $c->id }}" data-phone="{{ $c->phone }}" {{ (int)($selectedCustomerId ?? 0) === (int)$c->id ? 'selected' : '' }}>{{ $c->name }} ({{ $c->phone }})</option>@endforeach</select>
              <div id="member-badge" class="mt-1 d-none"><span class="badge bg-yellow-lt" id="member-badge-text"></span></div>
              {{-- WARNING PIUTANG (cocok exact party_name = nama customer, scope toko aktif).
                Server-side fallback utk pelanggan preselect (?customer_id= / scan member),
                JS syncDebtWarning() menangani ganti pilihan tanpa reload. --}}
              @if(!empty($selectedCustomerId) && isset($customerDebts[$selectedCustomerId]))
                <div class="alert alert-danger py-2 px-2 mt-1 mb-0 small" id="debt-warning-server">
                  Pelanggan ini masih punya piutang
                  <b>Rp {{ number_format($customerDebts[$selectedCustomerId]['total'], 0, ',', '.') }}</b>
                  ({{ $customerDebts[$selectedCustomerId]['n'] }} nota).
                  <br>
                  <span class="text-secondary">Sarankan lunasi dulu sebelum transaksi baru. Metode cicilan/DP tetap diizinkan — tidak diblokir.</span>
                </div>
              @endif
              <div id="debt-warning" class="alert alert-danger py-2 px-2 mt-1 mb-0 small d-none" role="alert"></div>
              <form method="GET" action="{{ route('pos.index') }}" class="mt-1">
                @if(!empty($q))
                  <input type="hidden" name="q" value="{{ $q }}">
                @endif
                <label class="form-label small text-secondary mb-1" for="scan_member">
                  Scan kartu member
                </label>
                <div class="input-group input-group-sm">
                  <input
                    type="text"
                    name="scan_member"
                    id="scan_member"
                    class="form-control"
                    placeholder="Scan barcode kartu / ketik kode / HP"
                    autocomplete="off"
                  >
                  <button class="btn btn-outline-primary" type="submit" title="Cari member by kode kartu, fallback no. HP">
                    Pilih
                  </button>
                </div>
              </form></div>
            <div class="col-6"><label class="form-label">Nama utk ditahan (F9)</label><input name="hold_name" id="hold_name" class="form-control" placeholder="cth: Bpk Andi" value="{{ $resumeName }}"></div>
            <div class="col-6"><label class="form-label">Kas / Akun</label>
              <select name="cash_account_id" class="form-select"><option value="">— Otomatis —</option>
              @foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>
            <div class="col-6"><label class="form-label">Diskon manual (Rp)</label><input name="discount" id="discount" type="number" min="0" value="0" class="form-control"></div>
            <div class="col-6"><label class="form-label">Kode promo</label>
              <div class="input-group">
                <input name="promo_code" id="promo_code" class="form-control" placeholder="Opsional" list="promo-list" oninput="refreshPromoSuggest()">
                <button type="button" class="btn btn-outline-info" id="btn-cek-promo" title="Validasi kode ke server & hitung hematnya">Cek</button>
              </div>
              <datalist id="promo-list">@foreach($promos as $p)<option value="{{ $p->code }}">{{ $p->name }}</option>@endforeach</datalist>
              <div id="promo-check-result" class="small mt-1"></div></div>
            <div class="col-12"><div id="promo-suggest" class="alert alert-info py-2 px-2 mb-0 d-none small"></div></div>
            <div class="col-6"><label class="form-label">Tukar tambah (Rp)</label><input name="trade_in_value" id="trade_in_value" type="number" min="0" value="0" class="form-control"></div>
            <div class="col-6"><label class="form-label">TT terdaftar</label>
              <select name="trade_in_id" class="form-select"><option value="">— Tanpa link —</option>
              @foreach($tradeIns as $t)<option value="{{ $t->id }}">{{ $t->old_brand }} {{ $t->old_type }} — Rp {{ number_format($t->appraisal_price,0,',','.') }}</option>@endforeach</select></div>
            <div class="col-6"><label class="form-label">Tenor cicilan (bln)</label><input name="installment_tenor" type="number" min="0" value="0" class="form-control"></div>
            <div class="col-12">
              <label class="form-label">
                Catatan / ucapan kado
              </label>
              <div class="input-group mb-2">
                <span
                  class="input-group-text"
                  title="Template cepat"
                >
                  <i class="ti ti-bolt"></i>
                </span>
                <select
                  id="note-template"
                  class="form-select"
                  onchange="applyNoteTemplate()"
                  title="Template cepat ucapan kado — mengisi kolom di bawah (masih bisa diedit)"
                >
                  <option value="">
                    — Template cepat —
                  </option>
                  <option value="hbd">
                    Selamat ulang tahun
                  </option>
                  <option value="thanks">
                    Terima kasih
                  </option>
                  <option value="sembuh">
                    Lekas sembuh
                  </option>
                  <option value="sukses">
                    Sukses selalu
                  </option>
                  <option value="custom">
                    Custom / tulis sendiri
                  </option>
                </select>
              </div>
              <input
                name="note"
                id="note-input"
                class="form-control"
                placeholder="Opsional — tampil besar di Struk Hadiah"
                value="{{ $resumeNote ?? '' }}"
              >
              <div class="form-text">
                Pilih template untuk mengisi otomatis, lalu edit sesukamu.
              </div>
            </div>
          </div>
          <label class="form-label mt-2">Metode bayar</label>
          <div class="btn-group w-100 flex-wrap" role="group">
            @foreach(['tunai'=>'Tunai','transfer'=>'Transfer','qris'=>'QRIS','cicilan'=>'Cicilan','dp'=>'DP'] as $k=>$v)
            <input type="radio" class="btn-check" name="payment_method" id="pm-{{ $k }}" value="{{ $k }}" {{ $k==='tunai'?'checked':'' }}>
            <label class="btn btn-outline-primary" for="pm-{{ $k }}">{{ $v }}</label>
            @endforeach
          </div>
          <div class="form-check mt-2">
            <input type="checkbox" class="form-check-input" id="split-toggle">
            <label class="form-check-label" for="split-toggle">Split payment (gabungan tunai + transfer/QRIS, total harus pas)</label>
          </div>
          <div id="split-box" class="border rounded p-2 mt-2 d-none">
            <div class="row g-2">
              <div class="col-6"><label class="form-label">Tunai (Rp)</label><input id="split-tunai" type="number" min="0" value="0" class="form-control"></div>
              <div class="col-6"><label class="form-label">Non-tunai</label>
                <div class="input-group">
                  <select id="split-method" class="form-select"><option value="transfer">Transfer</option><option value="qris">QRIS</option></select>
                  <input id="split-non" type="number" min="0" value="0" class="form-control">
                </div>
              </div>
            </div>
            <div class="small mt-1" id="split-info"></div>
          </div>
          <div id="single-box">
            <label class="form-label mt-2">Dibayar (Rp)</label>
            <input name="paid_amount" id="paid_amount" type="number" min="0" value="0" class="form-control form-control-lg">
            <div class="d-flex flex-wrap gap-2 mt-2" id="cash-quick">
              <button type="button" class="btn btn-sm btn-outline-primary" id="btn-pas" title="Isi persis sebesar total bayar">Uang Pas</button>
              <button type="button" class="btn btn-sm btn-outline-secondary" data-cash="50000">50rb</button>
              <button type="button" class="btn btn-sm btn-outline-secondary" data-cash="100000">100rb</button>
            </div>
            <div class="small mt-1" id="single-info"></div>
          </div>
          <div id="sig-box" class="border rounded p-2 mt-2 d-none">
            <div class="alert alert-warning py-2 px-2 mb-2">
              <b>Cicilan/DP wajib tanda tangan persetujuan</b>
              — klik <i>Ambil Tanda Tangan</i> dulu sebelum Simpan.
            </div>
            <div id="sigPreviewWrap" class="d-none">
              <label class="form-label mb-1">Pratinjau TTD</label>
              <div>
                <img
                  id="sigPreview"
                  src=""
                  alt="TTD persetujuan"
                  style="max-height: 90px; background: #fff; border: 1px dashed #98a2b3; border-radius: 6px;"
                >
              </div>
            </div>
            <div class="d-flex gap-2 mt-2">
              <button
                type="button"
                id="sigBtn"
                class="btn btn-outline-dark"
                onclick="openSigModal()"
              >
                <i class="ti ti-signature me-1"></i>Ambil Tanda Tangan
              </button>
              <button
                type="button"
                class="btn btn-ghost-secondary d-none"
                id="sigClearBtn"
                onclick="clearSig()"
              >
                Hapus
              </button>
            </div>
          </div>
          {{-- KIRIM STRUK WA OTOMATIS (opt-in, default MATI): hanya aktif bila
            pelanggan terpilih punya HP valid — dikunci via syncWaOptIn().
            Server mengabaikan bila HP tak valid (dilewati) & mencatat wa_logs
            via WaService (antre bila gateway belum dikonfigurasi). --}}
          <div class="form-check mt-2">
            <input type="checkbox" class="form-check-input" id="send_wa" name="send_wa" value="1">
            <label class="form-check-label" for="send_wa"><i class="ti ti-brand-whatsapp me-1"></i>Kirim struk via WA otomatis <span class="text-secondary small" id="send_wa_hint"></span></label>
          </div>
          <div class="d-flex justify-content-between mt-2 fs-3"><span>Subtotal</span><b id="t-sub">Rp 0</b></div>
          <div class="d-flex justify-content-between fs-3"><span>Total bayar</span><b id="t-tot" class="text-primary">Rp 0</b></div>
          <div class="d-flex justify-content-between text-secondary"><span id="t-chg-label">Kembalian / Sisa</span><b id="t-chg">Rp 0</b></div>
          <div class="row g-2 mt-3">
            <div class="col-6"><button type="button" class="btn btn-outline-warning w-100" id="btn-hold"><i class="ti ti-pause me-1"></i>Tahan (F9)</button></div>
            <div class="col-6"><button class="btn btn-success w-100" type="submit"><i class="ti ti-device-floppy me-1"></i>Simpan & Cetak</button></div>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Modal pelanggan baru inline (tanpa keluar POS): POST ke customers.store existing + redirect=pos -->
<div class="modal fade" id="modal-customer" tabindex="-1" aria-labelledby="modal-customer-label" aria-hidden="true">
  <div class="modal-dialog"><form method="POST" action="{{ route('customers.store') }}" class="modal-content">
    @csrf
    <input type="hidden" name="redirect" value="pos">
    <div class="modal-header"><h5 class="modal-title" id="modal-customer-label">Pelanggan Baru</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
    <div class="modal-body">
      <div class="mb-3"><label class="form-label">Nama <span class="text-danger">*</span></label>
        <input name="name" class="form-control" required maxlength="255" placeholder="cth: Budi Santoso" autocomplete="off"></div>
      <div class="mb-1"><label class="form-label">No. HP / WA</label>
        <input name="phone" class="form-control" maxlength="50" placeholder="cth: 0812xxxxxxx" autocomplete="off"></div>
      <div class="form-text">Nomor otomatis dinormalisasi (08… → 628…) seperti di master pelanggan. Setelah simpan, pelanggan langsung terpilih di POS.</div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
      <button class="btn btn-primary" type="submit"><i class="ti ti-device-floppy me-1"></i>Simpan & Pilih</button>
    </div>
  </form></div>
</div>

<!-- Modal TTD persetujuan cicilan/DP (canvas JS murni, cermin services/show, tanpa lib baru) -->
<div class="modal fade" id="sigModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Tanda tangan persetujuan</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning py-2 px-2 mb-2">
          Pelanggan menyetujui skema cicilan/DP.
          TTD wajib sebelum transaksi disimpan.
        </div>
        <canvas
          id="sigPad"
          width="440"
          height="220"
          style="width: 100%; height: 220px; background: #fff; border: 1px solid #d1d5db; border-radius: 8px; touch-action: none; cursor: crosshair;"
        ></canvas>
        <div class="text-secondary small mt-1">Gambar dengan mouse / jari.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost-secondary" onclick="clearSig()">Hapus</button>
        <button type="button" class="btn btn-primary" onclick="saveSig()">Pakai Tanda Tangan</button>
      </div>
    </div>
  </div>
</div>

<script>
// ---- Beep scan Web Audio (tanpa file, murni JS, ingat pilihan bisu di localStorage) ----
const BEEP_KEY = 'pos_beep_muted';
let beepMuted = false;
try{ beepMuted = localStorage.getItem(BEEP_KEY) === '1'; }catch(e){}
let beepCtx = null;
function beepAudio(){
  try{
    if(!beepCtx) beepCtx = new (window.AudioContext || window.webkitAudioContext)();
    if(beepCtx.state === 'suspended') beepCtx.resume();
    return beepCtx;
  }catch(e){ return null; }
}
function beepTone(freq, dur, type, delay){
  if(beepMuted) return;
  const ctx = beepAudio();
  if(!ctx) return;
  try{
    const t0 = ctx.currentTime + (delay || 0);
    const o = ctx.createOscillator();
    const g = ctx.createGain();
    o.type = type || 'sine';
    o.frequency.setValueAtTime(freq, t0);
    g.gain.setValueAtTime(0.0001, t0);
    g.gain.exponentialRampToValueAtTime(0.25, t0 + 0.01);
    g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
    o.connect(g); g.connect(ctx.destination);
    o.start(t0); o.stop(t0 + dur + 0.02);
  }catch(e){}
}
function beepOk(){ beepTone(880, 0.12, 'sine', 0); }                 // item masuk keranjang: tinggi pendek
function beepFail(){ beepTone(220, 0.16, 'square', 0); beepTone(175, 0.22, 'square', 0.14); } // gagal: rendah dobel
function beepApply(){
  const b = document.getElementById('btn-beep');
  if(b) b.innerHTML = beepMuted ? '<i class="ti ti-volume-off me-1"></i>Bisu' : '<i class="ti ti-volume me-1"></i>Bunyi: ON';
  try{ localStorage.setItem(BEEP_KEY, beepMuted ? '1' : '0'); }catch(e){}
}
document.getElementById('btn-beep')?.addEventListener('click', ()=>{
  beepMuted = !beepMuted; beepApply();
  if(!beepMuted) beepOk(); // umpan balik saat diaktifkan
  setTimeout(()=>{ const s = document.getElementById('scan'); if(s) s.focus(); }, 50);
});
beepApply();
let cart = @json($resumeCart ?? []);
function fmt(n){ return 'Rp ' + Number(n||0).toLocaleString('id-ID'); }
function cartTotal(){
  const disc = Number(document.getElementById('discount').value||0);
  const tt = Number(document.getElementById('trade_in_value').value||0);
  const sub = cart.reduce((s,c)=>s + c.qty*c.price, 0);
  return {sub, tot: Math.max(0, sub-disc-tt)};
}
function addToCart(pid, name, price, unitId, maxQty){
  let f = cart.find(i => i.product_id===pid && (i.product_item_id||null)===(unitId||null));
  if(f){ if(unitId){ beepFail(); alert('Unit IMEI hanya bisa 1x.'); return; } if(f.qty+1 > maxQty){ beepFail(); alert('Stok tidak cukup (sisa '+maxQty+').'); return; } f.qty++; }
  else cart.push({product_id:pid, product_item_id:unitId||null, name:name, qty:1, price:Number(price), max:maxQty});
  beepOk();
  render();
}
function rmRow(i){ cart.splice(i,1); render(); }
function syncSplit(){
  const on = document.getElementById('split-toggle').checked;
  document.getElementById('split-box').classList.toggle('d-none', !on);
  document.getElementById('single-box').classList.toggle('d-none', on);
  render();
}
function render(){
  const tb = document.getElementById('cart-body');
  if(!cart.length){ tb.innerHTML = '<tr id="cart-empty"><td colspan="4" class="text-center text-secondary">Keranjang kosong</td></tr>'; }
  else tb.innerHTML = cart.map((c,i)=>`<tr><td>${c.name}${c.product_item_id?'<br><span class="badge bg-green-lt">IMEI</span>':''}</td>
    <td><input type="number" min="1" max="${c.max}" value="${c.qty}" class="form-control form-control-sm" ${c.product_item_id?'disabled':''} onchange="cart[${i}].qty=Math.max(1,Math.min(${c.max},this.value|0));render()"></td>
    <td><input type="number" min="0" value="${c.price}" class="form-control form-control-sm" onchange="cart[${i}].price=this.value|0;render()"></td>
    <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="rmRow(${i})">×</button></td></tr>`).join('');
  const {sub, tot} = cartTotal();
  document.getElementById('t-sub').textContent = fmt(sub);
  document.getElementById('t-tot').textContent = fmt(tot);
  const on = document.getElementById('split-toggle').checked;
  const chgEl = document.getElementById('t-chg');
  const chgLabel = document.getElementById('t-chg-label');
  const pm = (document.querySelector('input[name="payment_method"]:checked')||{}).value || 'tunai';
  const isAngsuran = (pm === 'cicilan' || pm === 'dp');
  if(on){
    const a = Number(document.getElementById('split-tunai').value||0);
    const m = document.getElementById('split-method').value;
    const b = Number(document.getElementById('split-non').value||0);
    const info = document.getElementById('split-info');
    const diff = (a+b)-tot;
    if(a+b === tot && tot > 0){ info.innerHTML = '<span class="text-success">Pas: tunai '+fmt(a)+' + '+m+' '+fmt(b)+'</span>'; }
    else { info.innerHTML = '<span class="text-danger">Split Rp '+fmt(a+b)+' — harus pas Rp '+fmt(tot)+' (selisih '+fmt(a+b-tot)+')</span>'; }
    chgEl.textContent = fmt(diff);
    chgEl.className = diff < 0 ? 'text-danger' : 'text-success';
    if(chgLabel) chgLabel.textContent = 'Kembalian / Sisa (split)';
    document.getElementById('payments_json').value = JSON.stringify([{method:'tunai',amount:a},{method:m,amount:b}].filter(x=>x.amount>0));
  } else {
    const paid = Number(document.getElementById('paid_amount').value||0);
    const diff = paid-tot;
    const info = document.getElementById('single-info');
    if(chgLabel) chgLabel.textContent = isAngsuran ? 'Sisa kurang (cicilan/DP)' : 'Kembalian / Sisa';
    // Merah bila kurang, hijau bila pas/lebih. Cicilan/DP boleh kurang → info kuning, bukan error.
    chgEl.textContent = fmt(diff) + (diff<0 ? ' (kurang)' : (diff>0 && !isAngsuran ? ' (kembalian)' : ''));
    chgEl.className = diff < 0 ? (isAngsuran ? 'text-warning' : 'text-danger') : 'text-success';
    if(info){
      if(tot <= 0){ info.innerHTML = '<span class="text-secondary">Keranjang kosong.</span>'; }
      else if(diff < 0){
        info.innerHTML = isAngsuran
          ? '<span class="text-warning">DP/cicilan perdana ' + fmt(paid) + ' — sisa ' + fmt(-diff) + ' jadi piutang/sisa angsuran.</span>'
          : '<span class="text-danger">Kurang ' + fmt(-diff) + ' — tambah pembayaran atau aktifkan split/cicilan/DP.</span>';
      } else if(diff > 0){
        info.innerHTML = isAngsuran
          ? '<span class="text-success">Lebih ' + fmt(diff) + ' — cek tenor & DP, kelebihan jadi kembalian.</span>'
          : '<span class="text-success">Kembalian ' + fmt(diff) + '.</span>';
      } else {
        info.innerHTML = '<span class="text-success">Uang pas.</span>';
      }
    }
    document.getElementById('payments_json').value = '';
  }
  document.getElementById('items_json').value = JSON.stringify(cart.map(c=>({product_id:c.product_id,product_item_id:c.product_item_id,qty:c.qty,price:c.price,name:c.name,max:c.max})));
  refreshPromoSuggest();
  syncMemberPromo(false); // subtotal berubah → promo member bisa kini memenuhi min. belanja
  if(typeof syncSigUI === 'function') syncSigUI(); // box TTD ikut metode bayar cicilan/DP
}
// ---- Promo otomatis di POS (tanpa route baru): data promo aktif dikirim dari PosController@index,
// saran dihitung lokal tiap total berubah; tombol "Cek" validasi ke server via ?check_promo=.
const PROMOS = @json($promoSuggest ?? []);
const CUSTOMER_TIERS = @json($customerTiers ?? []);
let autoPromoCode = '';
function cartDistinct(){ return new Set(cart.map(c=>c.product_id)).size; }
function promoDisc(p, sub, distinct){
  const d = distinct ?? cartDistinct();
  if(p.type === 'diskon_persen') return Math.min(sub, sub * Math.min(100, Number(p.value)) / 100);
  if(p.type === 'bundling'){
    if(d < 2) return 0; // syarat bundling: ≥2 produk berbeda
    return Math.min(sub, sub * Math.min(100, Number(p.value)) / 100);
  }
  if(p.type === 'member'){
    const v = Number(p.value);
    if(v <= 100) return Math.min(sub, sub * v / 100);
    return Math.min(sub, v);
  }
  return Math.min(sub, Number(p.value)); // diskon_rupiah & voucher: nominal rupiah tetap
}
function promoLabel(p){
  if(p.type === 'diskon_persen' || p.type === 'bundling') return '-' + Number(p.value) + '%';
  if(p.type === 'member') return Number(p.value) <= 100 ? '-' + Number(p.value) + '%' : '-Rp ' + Number(p.value).toLocaleString('id-ID');
  return '-Rp ' + Number(p.value).toLocaleString('id-ID');
}
function currentTier(){
  const sel = document.getElementById('customer_id');
  const id = sel ? sel.value : '';
  return id ? (CUSTOMER_TIERS[id] || CUSTOMER_TIERS[String(id)] || '') : '';
}
function memberPromoFor(sub, tier){
  if(tier !== 'Silver' && tier !== 'Gold') return null;
  let best = null, bd = 0;
  const d = cartDistinct();
  PROMOS.filter(p=>p.memberOnly && p.dateOk && (p.sisa === null || p.sisa > 0) && sub >= Number(p.min_belanja))
    .forEach(p=>{ const dd = promoDisc(p, sub, d); if(dd > bd){ bd = dd; best = p; } });
  return best ? {promo: best, disc: bd} : null;
}
function bestPromoFor(sub, tier){
  let best = null, bd = 0;
  const d = cartDistinct();
  PROMOS.filter(p=>p.dateOk && (p.sisa === null || p.sisa > 0) && sub >= Number(p.min_belanja))
    .filter(p=>!p.memberOnly || tier === 'Silver' || tier === 'Gold')
    .filter(p=>p.type !== 'bundling' || d >= 2)
    .forEach(p=>{ const dd = promoDisc(p, sub, d); if(dd > bd){ bd = dd; best = p; } });
  return best ? {promo: best, disc: bd} : null;
}
// ---- WARNING PIUTANG (tanpa route/migration baru) ----
// debts tak punya customer_id → peta id => {n, total} dicocokkan exact
// party_name = nama customer di PosController@index (scope toko aktif).
// Aturan: warning merah + sarankan lunasi dulu + default-kan metode tunai,
// TAPI cicilan/DP TIDAK dinonaktifkan & submit TIDAK diblokir.
const CUSTOMER_DEBTS = @json($customerDebts ?? []);
function debtOf(id){
  if(!id) return null;
  return CUSTOMER_DEBTS[id] || CUSTOMER_DEBTS[String(id)] || null;
}
function syncDebtWarning(fromChange){
  const sel = document.getElementById('customer_id');
  const box = document.getElementById('debt-warning');
  const server = document.getElementById('debt-warning-server');
  if(!sel || !box) return;
  const d = debtOf(sel.value);
  const pm = (document.querySelector('input[name="payment_method"]:checked')||{}).value || 'tunai';
  if(server) server.classList.toggle('d-none', !!d); // hindari badge ganda saat JS aktif
  if(!d){ box.classList.add('d-none'); box.innerHTML = ''; return; }
  const isAngsuran = (pm === 'cicilan' || pm === 'dp');
  box.classList.remove('d-none');
  box.innerHTML = 'Piutang <b>' + fmt(d.total) + '</b> (' + d.n + ' nota).'
    + '<br><span>Sarankan lunasi dulu sebelum transaksi baru.</span>'
    + (isAngsuran
      ? '<br><span class="text-warning fw-bold">Metode ' + pm.toUpperCase() + ' tetap diizinkan untuk pelanggan berpiutang — pastikan piutang lama ikut dibayar.</span>'
      : '');
  // Paling aman: kembalikan default ke tunai saat ganti pelanggan berpiutang,
  // kasir tetap bebas memilih ulang cicilan/DP (tidak di-disable).
  if(fromChange && isAngsuran){
    const tunai = document.getElementById('pm-tunai');
    if(tunai){ tunai.checked = true; render(); syncDebtWarning(false); }
  }
}
document.getElementById('customer_id')?.addEventListener('change', ()=>syncDebtWarning(true));
document.getElementById('customer_id')?.addEventListener('change', ()=>syncWaOptIn());
document.querySelectorAll('input[name="payment_method"]').forEach(r=>r.addEventListener('change',()=>syncDebtWarning(false)));
// Pelanggan Silver/Gold + ada promo member aktif → otomatis isi kode promo (badge ).
// Tidak menimpa kode yang diketik kasir manual.
function syncMemberPromo(fromChange){
  const input = document.getElementById('promo_code');
  const badge = document.getElementById('member-badge');
  const badgeText = document.getElementById('member-badge-text');
  const {sub} = cartTotal();
  const tier = currentTier();
  const m = memberPromoFor(sub, tier);
  if(m){
    badge.classList.remove('d-none');
    badgeText.textContent = 'Member ' + tier + ' ' + promoLabel(m.promo) + ' — kode ' + m.promo.code + ' otomatis dipakai';
    if(!input.value.trim() || input.value.trim().toUpperCase() === autoPromoCode){
      input.value = m.promo.code;
      autoPromoCode = m.promo.code.toUpperCase();
    }
  } else {
    if(tier){
      badge.classList.remove('d-none');
      badgeText.textContent = (tier === 'Silver' || tier === 'Gold')
        ? 'Member ' + tier + ' — belum ada promo member yang memenuhi belanja saat ini.'
        : 'Member ' + tier + ' — promo member khusus Silver/Gold.';
    } else badge.classList.add('d-none');
    if(input.value.trim().toUpperCase() === autoPromoCode && autoPromoCode !== ''){ input.value = ''; autoPromoCode = ''; }
  }
  if(fromChange) refreshPromoSuggest();
}
function refreshPromoSuggest(){
  const box = document.getElementById('promo-suggest');
  if(!box) return;
  const {sub} = cartTotal();
  const tier = currentTier();
  const typed = (document.getElementById('promo_code').value || '').trim().toUpperCase();
  const found = bestPromoFor(sub, tier);
  if(found && found.disc > 0 && found.promo.code.toUpperCase() !== typed){
    box.classList.remove('d-none');
    const cd = found.promo.countdown
      ? '<br><span class="text-warning">Berakhir: ' + found.promo.countdown + '</span>'
      : '';
    box.innerHTML = 'Promo tersedia: <b>' + found.promo.code + '</b> <span class="badge bg-blue-lt">' + (found.promo.jenis || found.promo.type) + '</span>'
      + ' (' + found.promo.name + ') — hemat ' + fmt(found.disc)
      + cd
      + '<br><span class="text-secondary">Syarat: ' + (found.promo.syarat || ('min. belanja ' + fmt(found.promo.min_belanja))) + '</span>'
      + ' <button type="button" class="btn btn-sm btn-info ms-1" onclick="usePromo(\'' + found.promo.code + '\')">Pakai</button>';
  } else box.classList.add('d-none');
}
// ---- KIRIM STRUK WA OTOMATIS (opt-in, default MATI) ----
// Checkbox hanya aktif bila pelanggan terpilih punya HP valid
// (normalisasi 0… → 62…, panjang 9–15 digit, cermin guard server).
// Tanpa HP valid → dikunci + dilepas ceklisnya (server pun melewati).
function normWaPhone(raw){
  let p = String(raw || '').replace(/\D/g, '');
  if(p.startsWith('0')) p = '62' + p.slice(1);
  return p;
}
function syncWaOptIn(){
  const sel = document.getElementById('customer_id');
  const cb = document.getElementById('send_wa');
  const hint = document.getElementById('send_wa_hint');
  if(!sel || !cb) return;
  const opt = sel.selectedOptions && sel.selectedOptions[0];
  const p = normWaPhone(opt ? (opt.dataset.phone || '') : '');
  const valid = p.length >= 9 && p.length <= 15;
  cb.disabled = !valid;
  if(!valid) cb.checked = false;
  if(hint) hint.textContent = valid ? ('ke ' + p) : '(pilih pelanggan dengan no. HP valid dulu)';
}
function usePromo(code){ document.getElementById('promo_code').value = code; render(); }
function holdCart(){
  render();
  if(!cart.length){ alert('Keranjang masih kosong.'); return; }
  if(!document.getElementById('hold_name').value){ alert('Isi "Nama utk ditahan" dulu (cth: nama pelanggan).'); document.getElementById('hold_name').focus(); return; }
  document.getElementById('hold_cart').value = '1';
  document.getElementById('form-pos').submit();
}
['discount','trade_in_value','paid_amount','split-tunai','split-non'].forEach(id=>document.getElementById(id).addEventListener('input',render));
document.querySelectorAll('input[name="payment_method"]').forEach(r=>r.addEventListener('change',render));
document.querySelectorAll('#cash-quick [data-cash]').forEach(b=>b.addEventListener('click',()=>{
  const p = document.getElementById('paid_amount');
  p.value = Number(b.dataset.cash||0); render(); p.focus();
}));
document.getElementById('btn-pas').addEventListener('click',()=>{
  const {tot} = cartTotal();
  const p = document.getElementById('paid_amount');
  p.value = tot; render(); p.focus();
});
document.getElementById('split-method').addEventListener('change',render);
document.getElementById('split-toggle').addEventListener('change',syncSplit);
document.getElementById('btn-hold').onclick = holdCart;
const catalog = [
  @foreach($stocks as $s){pid:{{ $s->product->id }}, name:@json($s->product->name), price:{{ $s->product->selling_price }}, unit:null, max:{{ $s->qty }}, keys:@json(strtolower($s->product->sku.' '.$s->product->barcode.' '.$s->product->name))},
  @endforeach
  @foreach($units as $u){pid:{{ $u->product_id }}, name:@json($u->product->name.' ('.($u->imei??'unit').')'), price:{{ $u->selling_price }}, unit:{{ $u->id }}, max:1, keys:@json(strtolower(($u->imei??'').' '.($u->serial??'').' '.$u->product->name))},
  @endforeach
];
function doScan(){
  const el = document.getElementById('scan');
  const key = el.value.trim().toLowerCase();
  if(!key) return;
  const hit = catalog.find(c=>c.keys.includes(key));
  if(hit){ if(hit.max<=0 && !hit.unit){ beepFail(); alert('Stok habis, tidak bisa ditambah.'); el.value=''; el.focus(); return; } addToCart(hit.pid, hit.name, hit.price, hit.unit, hit.max); el.value=''; }
  else {
    beepFail();
    document.querySelectorAll('#tbl-produk tbody tr').forEach(tr=>{
      tr.style.display = tr.dataset.name.includes(key) ? '' : 'none';
    });
    alert('Tidak cocok persis — tabel difilter. Klik + Keranjang manual bila sesuai.');
  }
  el.focus();
}
document.getElementById('btn-cari').onclick = doScan;
document.getElementById('scan').addEventListener('keydown', e=>{ if(e.key==='Enter'){ e.preventDefault(); doScan(); } });
// Tombol "Cek": validasi kode promo ke server (query ?check_promo=, tanpa route baru).
document.getElementById('btn-cek-promo').onclick = async ()=>{
  const code = (document.getElementById('promo_code').value || '').trim();
  const res = document.getElementById('promo-check-result');
  const {sub} = cartTotal();
  if(!code){ res.innerHTML = '<span class="text-danger">Isi kode promo dulu.</span>'; return; }
  res.innerHTML = '<span class="text-secondary">Mengecek…</span>';
  try{
    const url = new URL(window.location.href);
    url.searchParams.set('check_promo', code);
    url.searchParams.set('subtotal', sub);
    url.searchParams.set('distinct', cartDistinct());
    const cid = document.getElementById('customer_id').value;
    if(cid) url.searchParams.set('customer_id', cid); else url.searchParams.delete('customer_id');
    const r = await fetch(url.toString(), {headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
    const j = await r.json();
    res.innerHTML = j.valid
      ? '<span class="text-success">' + j.message + (j.syarat ? '<br><span class="text-secondary">Syarat: ' + j.syarat + '</span>' : '') + '</span>'
      : '<span class="text-danger">' + (j.message || 'Promo tidak valid.') + '</span>';
  }catch(e){ res.innerHTML = '<span class="text-danger">Gagal mengecek promo (jaringan).</span>'; }
};
// ---- TTD PERSETUJUAN CICILAN/DP (canvas JS murni, cermin services/show, tanpa lib baru) ----
// Wajib bila metode cicilan/dp & split payment MATI (split hanya tunai/transfer/qris,
// server menimpa payment_method ke leg dominan sehingga tak memicu kewajiban TTD).
var sigCanvas = null;
var sigCtx = null;
var sigDrawing = false;
var sigHasDraw = false;
var sigLast = null;
function sigPos(e){
  var r = sigCanvas.getBoundingClientRect();
  var cx = (e.touches && e.touches[0]) ? e.touches[0].clientX : e.clientX;
  var cy = (e.touches && e.touches[0]) ? e.touches[0].clientY : e.clientY;
  return {
    x: (cx - r.left) * (sigCanvas.width / r.width),
    y: (cy - r.top) * (sigCanvas.height / r.height)
  };
}
function sigSetup(){
  sigCanvas = sigCanvas || document.getElementById('sigPad');
  if(!sigCanvas || sigCtx) return;
  sigCtx = sigCanvas.getContext('2d');
  sigCtx.lineWidth = 2.5;
  sigCtx.lineCap = 'round';
  sigCtx.lineJoin = 'round';
  sigCtx.strokeStyle = '#111';
}
function sigStart(e){
  sigSetup();
  sigDrawing = true;
  sigHasDraw = true;
  sigLast = sigPos(e);
  if(e.cancelable) e.preventDefault();
}
function sigMove(e){
  if(!sigDrawing) return;
  var p = sigPos(e);
  sigCtx.beginPath();
  sigCtx.moveTo(sigLast.x, sigLast.y);
  sigCtx.lineTo(p.x, p.y);
  sigCtx.stroke();
  sigLast = p;
  if(e.cancelable) e.preventDefault();
}
function sigEnd(){
  sigDrawing = false;
}
function sigRequired(){
  var pm = (document.querySelector('input[name="payment_method"]:checked') || {}).value || 'tunai';
  var splitOn = document.getElementById('split-toggle').checked;
  return !splitOn && (pm === 'cicilan' || pm === 'dp');
}
function syncSigUI(){
  var need = sigRequired();
  var box = document.getElementById('sig-box');
  var hint = document.getElementById('sigPreviewWrap');
  var clearBtn = document.getElementById('sigClearBtn');
  var sigInput = document.getElementById('sig-data');
  var hasSig = !!(sigInput && sigInput.value);
  if(box) box.classList.toggle('d-none', !need);
  if(hint) hint.classList.toggle('d-none', !hasSig);
  if(clearBtn) clearBtn.classList.toggle('d-none', !hasSig);
  // Nilai sig-data dipertahankan saat ganti metode (server mengabaikan bila bukan cicilan/DP),
  // agar TTD tak hilang bila kasir salah klik radio lalu kembali ke cicilan/DP.
}
function openSigModal(){
  sigSetup();
  var m = document.getElementById('sigModal');
  try{
    var inst = bootstrap.Modal.getOrCreateInstance(m);
    inst.show();
  }catch(e){
    m.classList.add('show');
    m.style.display = 'block';
  }
}
function closeSigModal(){
  var m = document.getElementById('sigModal');
  try{
    var inst = bootstrap.Modal.getInstance(m);
    if(inst) inst.hide();
    else{
      m.classList.remove('show');
      m.style.display = 'none';
    }
  }catch(e){
    m.classList.remove('show');
    m.style.display = 'none';
  }
}
function clearSig(){
  sigSetup();
  sigCtx.clearRect(0, 0, sigCanvas.width, sigCanvas.height);
  sigHasDraw = false;
  var input = document.getElementById('sig-data');
  if(input) input.value = '';
  var prev = document.getElementById('sigPreview');
  if(prev) prev.src = '';
  syncSigUI();
}
function saveSig(){
  if(!sigHasDraw){
    alert('Coret dulu tanda tangannya.');
    return;
  }
  var url = sigCanvas.toDataURL('image/png');
  var input = document.getElementById('sig-data');
  if(input) input.value = url;
  var prev = document.getElementById('sigPreview');
  if(prev) prev.src = url;
  closeSigModal();
  syncSigUI();
}
(function sigInit(){
  sigSetup();
  if(sigCanvas){
    sigCanvas.addEventListener('mousedown', sigStart);
    sigCanvas.addEventListener('mousemove', sigMove);
    window.addEventListener('mouseup', sigEnd);
    sigCanvas.addEventListener('touchstart', sigStart, {passive: false});
    sigCanvas.addEventListener('touchmove', sigMove, {passive: false});
    sigCanvas.addEventListener('touchend', sigEnd);
  }
  document.querySelectorAll('input[name="payment_method"]').forEach(function(r){
    r.addEventListener('change', syncSigUI);
  });
  document.getElementById('split-toggle')?.addEventListener('change', syncSigUI);
})();
document.getElementById('form-pos').addEventListener('submit', e=>{
  render();
  if(document.getElementById('hold_cart').value){ return; } // tahan struk: validasi ringan di server
  if(!cart.length){ e.preventDefault(); alert('Keranjang masih kosong.'); return; }
  if(sigRequired() && !document.getElementById('sig-data').value){
    e.preventDefault();
    syncSigUI();
    openSigModal();
    alert('Cicilan/DP wajib tanda tangan persetujuan dulu.');
    return;
  }
  if(document.getElementById('split-toggle').checked){
    const {tot} = cartTotal();
    const legs = JSON.parse(document.getElementById('payments_json').value||'[]');
    const sum = legs.reduce((s,l)=>s+Number(l.amount||0),0);
    if(Math.abs(sum-tot) > 1){ e.preventDefault(); alert('Split payment harus pas dengan total bayar.'); }
  } else {
    const pm0 = (document.querySelector('input[name="payment_method"]:checked')||{}).value || 'tunai';
    if(pm0 !== 'cicilan' && pm0 !== 'dp'){
      const {tot} = cartTotal();
      const paid0 = Number(document.getElementById('paid_amount').value||0);
      if(paid0 < tot){ e.preventDefault(); alert('Dibayar kurang ' + fmt(tot-paid0) + ' — gunakan Uang Pas / nominal cepat, split, atau pilih Cicilan/DP.'); }
    }
  }
});
// Shortcut kasir: F2 fokus scan, F4 bayar tunai, F9 tahan struk, Esc reset
document.addEventListener('keydown', e=>{
  if(e.key==='F2'){ e.preventDefault(); document.getElementById('scan').focus(); }
  else if(e.key==='F4'){ e.preventDefault(); document.getElementById('split-toggle').checked=false; syncSplit(); document.getElementById('pm-tunai').checked=true; const p=document.getElementById('paid_amount'); p.focus(); p.select(); }
  else if(e.key==='F9'){ e.preventDefault(); holdCart(); }
  else if(e.key==='Escape'){ if(cart.length && confirm('Reset keranjang?')){ cart=[]; render(); } }
});
// ---- Mode Kasir layar penuh (murni view/JS, tanpa backend) ----
// Fullscreen API + class body.kasir-mode (tombol/input lebih besar,
// header & nav global disembunyikan via CSS). Pilihan diingat di localStorage.
const KASIR_KEY = 'pos_kasir_mode';
function kasirFullscreenOn(){
  const el = document.documentElement;
  try{
    if(!document.fullscreenElement && el.requestFullscreen){
      el.requestFullscreen().catch(()=>{});
    }
  }catch(e){}
}
function kasirFullscreenOff(){
  try{
    if(document.fullscreenElement && document.exitFullscreen){
      document.exitFullscreen().catch(()=>{});
    }
  }catch(e){}
}
function kasirApply(on){
  document.body.classList.toggle('kasir-mode', !!on);
  const b = document.getElementById('btn-kasir-mode');
  if(b){
    b.innerHTML = on ? '× Keluar Mode Kasir' : '<i class="ti ti-device-desktop me-1"></i> Mode Kasir';
    b.classList.toggle('btn-success', !!on);
    b.classList.toggle('btn-dark', !on);
  }
  try{ localStorage.setItem(KASIR_KEY, on ? '1' : '0'); }catch(e){}
}
function toggleKasirMode(){
  const on = !document.body.classList.contains('kasir-mode');
  kasirApply(on);
  if(on){ kasirFullscreenOn(); } else { kasirFullscreenOff(); }
  setTimeout(function(){
    const s = document.getElementById('scan');
    if(s) s.focus();
  }, 150);
}
(function kasirInit(){
  const b = document.getElementById('btn-kasir-mode');
  if(b) b.addEventListener('click', toggleKasirMode);
  let saved = '0';
  try{ saved = localStorage.getItem(KASIR_KEY) || '0'; }catch(e){}
  // Terapkan class tampilan besar dari kunjungan sebelumnya.
  // Fullscreen TIDAK otomatis (butuh gestur klik) — kasir klik tombol sekali bila ingin penuh.
  if(saved === '1') kasirApply(true);
})();
// ---- Template cepat ucapan kado (murni view/JS, isi input note, masih bisa diedit) ----
const NOTE_TEMPLATES = {
  hbd: 'Selamat Ulang Tahun! \u{1F382} Semoga panjang umur, sehat selalu, dan semua impianmu tercapai. \u{1F389}',
  thanks: 'Terima kasih banyak! \u{1F64F} Hadiah kecil ini sebagai tanda apresiasi dan ketulusan kami. \u{1F49D}',
  sembuh: 'Lekas sembuh ya! \u{1F917} Semoga cepat pulih dan kembali beraktivitas seperti sedia kala. \u{1F4AA}',
  sukses: 'Sukses selalu! \u{1F31F} Semoga hadiah ini membawa semangat baru menuju keberhasilan. \u{1F680}'
};
function applyNoteTemplate(){
  const sel = document.getElementById('note-template');
  const input = document.getElementById('note-input');
  if(!sel || !input) return;
  const v = sel.value;
  if(v === 'custom'){
    input.value = '';
    input.focus();
    return;
  }
  if(v === ''){
    return;
  }
  if(NOTE_TEMPLATES[v]){
    input.value = NOTE_TEMPLATES[v];
    input.focus();
  }
  // Pilihan template dipertahankan agar kasir tahu template terakhir;
  // kolom input tetap bebas diedit manual setelah diisi.
}
render();
syncDebtWarning(false);
syncWaOptIn();
</script>
@endsection
