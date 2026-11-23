<!doctype html>
<html lang="id" data-bs-theme="light">
<head>
  <script>
    /* Set tema sebelum paint (anti-FOUC). Kunci: servicehp-theme. Default terang; hormati prefers-color-scheme saat pertama. */
    (function(){try{var k='servicehp-theme';var t=localStorage.getItem(k);if(!t){t=(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light';}document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}})();
  </script>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
  <meta name="theme-color" content="#206bc4"/>
  <title>@yield('title', 'ServiceHP') — Sistem Toko HP & Service Multi-Cabang</title>
  <link rel="manifest" href="/manifest.webmanifest">
  <link rel="icon" href="/icons/icon-192.png">
  <link rel="apple-touch-icon" href="/apple-touch-icon.png">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="ServiceHP">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css" rel="stylesheet"/>
  <link href="/css/corporate.css" rel="stylesheet"/>
  <style>
    .navbar-brand b { letter-spacing:.3px }
    @media print { .no-print { display:none!important } }
    /* Struk/print area: selalu putih walau mode gelap aktif */
    @media print {
      body, .page, .page-wrapper, .page-body, .container-xl { background:#fff !important; color:#000 !important; }
      .card, .struk-58, .struk-80, #struk, [id^="struk"] { background:#fff !important; color:#000 !important; border-color:#000 !important; box-shadow:none !important; }
      .struk-58 *, .struk-80 *, #struk *, [id^="struk"] * { color:#000 !important; background:transparent !important; }
      a { color:#000 !important; text-decoration:none !important; }
    }
    .struk-58 { width:58mm; font-family:monospace; font-size:11px; background:#fff; color:#000 }
    .struk-80 { width:80mm; font-family:monospace; font-size:12px; background:#fff; color:#000 }
    .empty-icon { font-size:2.75rem; line-height:1 }
    @media (max-width:576px){
      .page-header .col-auto { display:flex; flex-wrap:wrap; gap:.5rem; width:100%; margin-top:.5rem }
      .page-header .col-auto .btn { flex:1 1 auto; min-height:44px }
      .btn-group.flex-wrap .btn { flex:1 1 auto; min-height:44px }
      .table .btn { min-height:36px }
      input.form-control, select.form-select { font-size:16px }
    }
  </style>
  @stack('css')
</head>
<body>
<div class="page">
  {{-- Header corporate --}}
  <header class="navbar navbar-expand-md d-print-none no-print corporate-bar">
    <div class="container-xl">
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbar-menu">
        <span class="navbar-toggler-icon"></span>
      </button>
      <h1 class="navbar-brand navbar-brand-autodark d-none-navbar-horizontal pe-0 pe-md-3">
        <a href="{{ route('dashboard') }}" class="text-decoration-none d-flex align-items-center gap-2"><span class="corp-brandmark">S</span><span><span class="corp-brandname">ServiceHP</span><br><span class="corp-storename">POS · Inventory · Service Center</span></span></a>
        @if(session('active_store_id') && isset($allStores))
          <span class="badge bg-blue-lt ms-2">{{ optional($allStores->firstWhere('id', session('active_store_id')))->name ?? 'Toko' }}</span>
        @endif
      </h1>
      {{-- Pencarian global (desktop): GET /search?q= — hasil dikelompokkan, limit 5/kelompok. Shortcut "/" fokus ke sini. --}}
      <form
        method="GET"
        action="{{ route('search') }}"
        class="d-none d-md-flex ms-2 me-auto"
        role="search"
      >
        <div
          class="input-icon"
        >
          <span
            class="input-icon-addon"
          >
            <i
              class="ti ti-search"
            ></i>
          </span>
          <input
            type="text"
            name="q"
            value="{{ request('q') }}"
            class="form-control form-control-sm js-global-search"
            id="global-search-desktop"
            placeholder="Cari invoice / tiket / IMEI / pelanggan / produk…  ( tekan / )"
            autocomplete="off"
          />
        </div>
      </form>
      <div class="navbar-nav flex-row order-md-last">
        {{-- Lonceng notifikasi live: badge = siap_diambil + overdue + booking menunggu.
          Polling ringan via GET /notifications/count tiap 60 dtk (lihat @stack js di bawah).
          Sengaja tanpa bunyi agar tidak mengganggu kasir/teknisi di toko yang ramai. --}}
        <div
          class="nav-item me-2 d-flex align-items-center"
        >
          <a
            href="{{ route('dashboard') }}"
            id="notif-bell"
            class="btn btn-icon btn-ghost-secondary position-relative"
            title="Notifikasi: tiket siap diambil, tiket terlambat, booking menunggu"
            aria-label="Notifikasi"
          >
            <span aria-hidden="true"><i class="ti ti-bell" style="font-size:18px"></i></span>
            <span
              id="notif-badge"
              class="badge bg-red position-absolute top-0 start-100 translate-middle"
              style="display:none"
            >
              0
            </span>
          </a>
        </div>
        {{-- Toggle gelap/terang --}}
        <div class="nav-item me-2 d-flex align-items-center">
          <button type="button" id="theme-toggle" class="btn btn-icon btn-ghost-secondary" title="Mode gelap/terang" aria-label="Mode gelap/terang">
            <span id="theme-icon"><i class="ti ti-moon" style="font-size:18px"></i></span>
          </button>
        </div>
        {{-- Store switcher --}}
        @if(isset($allStores) && $allStores->count())
        <form method="POST" action="#" class="nav-item me-2 d-flex align-items-center" onsubmit="return false">
          <select class="form-select form-select-sm" onchange="fetch('{{ url('/store') }}/'+this.value,{method:'POST',headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}'}}).then(()=>location.reload())">
            @foreach($allStores as $s)
              <option value="{{ $s->id }}" {{ session('active_store_id')==$s->id?'selected':'' }}>{{ $s->name }}</option>
            @endforeach
          </select>
        </form>
        @endif
        <div class="nav-item dropdown">
          <a href="#" class="nav-link d-flex lh-1 text-reset p-0" data-bs-toggle="dropdown">
            <span class="avatar avatar-sm">{{ strtoupper(substr(auth()->user()->name ?? 'A',0,1)) }}</span>
            <div class="d-none d-xl-block ps-2">
              <div>{{ auth()->user()->name ?? '' }}</div>
              <div class="mt-1 small text-secondary">{{ auth()->user()->role ?? '' }}</div>
            </div>
          </a>
          <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
            <a class="dropdown-item" href="{{ route('profile') }}">Profil Saya</a>
            <div class="dropdown-divider"></div>
            <form method="POST" action="{{ route('logout') }}">@csrf
              <button class="dropdown-item">Logout</button>
            </form>
          </div>
        </div>
      </div>
      <div class="collapse navbar-collapse" id="navbar-menu">
        <ul class="navbar-nav">
          {{-- Pencarian global (mobile): tampil hanya di menu collapse --}}
          <li class="nav-item d-md-none w-100">
            <form
              method="GET"
              action="{{ route('search') }}"
              class="px-3 py-2"
              role="search"
            >
              <div
                class="input-icon"
              >
                <span
                  class="input-icon-addon"
                >
                  <i
                    class="ti ti-search"
                  ></i>
                </span>
                <input
                  type="text"
                  name="q"
                  value="{{ request('q') }}"
                  class="form-control js-global-search"
                  id="global-search-mobile"
                  placeholder="Cari invoice / tiket / IMEI / pelanggan / produk…  ( tekan / )"
                  autocomplete="off"
                />
              </div>
            </form>
          </li>
          <li class="nav-item"><a class="nav-link" href="{{ route('dashboard') }}"><i class="ti ti-dashboard me-1"></i>Dashboard</a></li>
          <li class="nav-item"><a class="nav-link" href="{{ route('activity') }}"><i class="ti ti-history me-1"></i>Aktivitas</a></li>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" data-bs-toggle="dropdown" href="#"><i class="ti ti-shopping-cart me-1"></i>Penjualan</a>
            <div class="dropdown-menu">
              <a class="dropdown-item" href="{{ route('pos.index') }}">POS / Kasir</a>
              <a class="dropdown-item" href="{{ route('sales.index') }}">Riwayat Transaksi</a>
              <a class="dropdown-item" href="{{ route('returns.index') }}">Retur & Refund</a>
              <a class="dropdown-item" href="{{ route('tradeins.index') }}">Tukar Tambah</a>
              <a class="dropdown-item" href="{{ route('secondhand.index') }}">Beli HP Bekas</a>
              <a class="dropdown-item" href="{{ route('consignments.index') }}">Konsinyasi</a>
            </div>
          </li>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" data-bs-toggle="dropdown" href="#"><i class="ti ti-device-mobile me-1"></i>Inventory</a>
            <div class="dropdown-menu">
              <a class="dropdown-item" href="{{ route('products.index') }}">Produk & HP</a>
              <a class="dropdown-item" href="{{ route('units.index') }}">Unit / IMEI</a>
              <a class="dropdown-item" href="{{ route('stocks.opname') }}">Stok Opname</a>
              <a class="dropdown-item" href="{{ route('stocks.mutations') }}">Mutasi & Transfer</a>
            </div>
          </li>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" data-bs-toggle="dropdown" href="#"><i class="ti ti-tool me-1"></i>Service</a>
            <div class="dropdown-menu">
              <a class="dropdown-item" href="{{ route('services.index') }}">Tiket Service</a>
              <a class="dropdown-item" href="{{ route('services.report') }}">Laporan Service</a>
              <a class="dropdown-item" href="{{ route('services.create') }}">Terima HP Baru</a>
              <a class="dropdown-item" href="{{ route('bookings.index') }}">Booking Online</a>
              <a class="dropdown-item" href="{{ route('technicians.index') }}">Teknisi & Komisi</a>
            </div>
          </li>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" data-bs-toggle="dropdown" href="#"><i class="ti ti-wallet me-1"></i>Keuangan</a>
            <div class="dropdown-menu">
              <a class="dropdown-item" href="{{ route('cash.index') }}">Kas Masuk/Keluar</a>
              <a class="dropdown-item" href="{{ route('expenses.index') }}">Pengeluaran</a>
              <a class="dropdown-item" href="{{ route('debts.index') }}">Hutang & Piutang</a>
              <a class="dropdown-item" href="{{ route('reports.profit') }}">Laba Rugi</a>
              <a class="dropdown-item" href="{{ route('purchases.index') }}">Pembelian Supplier</a>
            </div>
          </li>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" data-bs-toggle="dropdown" href="#"><i class="ti ti-settings me-1"></i>Master</a>
            <div class="dropdown-menu">
              <a class="dropdown-item" href="{{ route('stores.index') }}">Toko / Cabang</a>
              @if(in_array(auth()->user()->role ?? '', ['superadmin','owner']))
              <a class="dropdown-item" href="{{ route('stores.monitor') }}"><i class="ti ti-building me-1"></i>Monitoring Pusat</a>
              @endif
              <a class="dropdown-item" href="{{ route('customers.index') }}">Pelanggan (CRM)</a>
              <a class="dropdown-item" href="{{ route('suppliers.index') }}">Supplier</a>
              <a class="dropdown-item" href="{{ route('brands.index') }}">Merek</a>
              <a class="dropdown-item" href="{{ route('categories.index') }}">Kategori</a>
              <a class="dropdown-item" href="{{ route('promos.index') }}">Promo & Voucher</a>
              <a class="dropdown-item" href="{{ route('reminders.index') }}">
                <i class="ti ti-bell me-1"></i>Pengingat
              </a>
              <a class="dropdown-item" href="{{ route('users.index') }}">Pengguna</a>
              <a class="dropdown-item" href="{{ route('settings.printers') }}"><i class="ti ti-printer me-1"></i>Printer & Cetak</a>
              <a class="dropdown-item" href="{{ route('backup.index') }}"><i class="ti ti-database me-1"></i>Backup Data</a>
              @if(in_array(auth()->user()->role ?? '', ['superadmin','owner']))
              <a class="dropdown-item" href="{{ route('storage.index') }}"><i class="ti ti-folder me-1"></i>File Yatim (Storage)</a>
              @endif
              @if(in_array(auth()->user()->role ?? '', ['superadmin','owner','admin']))
              <a class="dropdown-item" href="{{ route('settings.wa') }}">WhatsApp</a>
              @endif
            </div>
          </li>
          <li class="nav-item"><a class="nav-link" href="{{ route('track.form') }}" target="_blank"><i class="ti ti-search me-1"></i>Lacak Service</a></li>
          <li class="nav-item d-print-none"><a class="nav-link" href="{{ route('help') }}"><i class="ti ti-help me-1"></i>Bantuan</a></li>
        </ul>
      </div>
    </div>
  </header>

  <div class="page-wrapper">
    <div class="page-body">
      <div class="container-xl">
        @if(session('success'))<div class="alert alert-success alert-dismissible no-print"><div class="d-flex"><div>{{ session('success') }}</div></div><a class="btn-close" data-bs-dismiss="alert"></a></div>@endif
        @if(session('warning'))<div class="alert alert-warning alert-dismissible no-print"><div class="d-flex"><div>{{ session('warning') }}</div></div><a class="btn-close" data-bs-dismiss="alert"></a></div>@endif
        @if(session('error'))<div class="alert alert-danger alert-dismissible no-print"><div>{{ session('error') }}</div><a class="btn-close" data-bs-dismiss="alert"></a></div>@endif
        @if($errors->any())<div class="alert alert-danger no-print"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
        @yield('content')
      </div>
    </div>
    @php
      $appVersion = env('APP_VERSION', '2026.11');
    @endphp
    <footer
      class="footer footer-transparent d-print-none no-print"
    >
      <div
        class="container-xl"
      >
        <div
          class="row text-center align-items-center flex-row-reverse"
        >
          <div
            class="col-lg-auto ms-lg-auto"
          >
            <span
              class="badge bg-blue-lt"
              title="Versi aplikasi ServiceHP"
            >
              v{{ $appVersion }}
            </span>
          </div>
          <div
            class="col-12 col-lg-auto mt-3 mt-lg-0"
          >
            <ul
              class="list-inline list-inline-dots mb-0"
            >
              <li
                class="list-inline-item"
              >
                <b>ServiceHP</b> <span class="text-secondary">— Sistem Toko HP & Service Multi-Cabang</span>
              </li>
              <li
                class="list-inline-item"
              >
                <a
                  href="{{ route('help') }}"
                >
                  Bantuan
                </a>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </footer>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/js/tabler.min.js"></script>
<script>
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/sw.js').then(function(reg){
    function promptUpdate(worker){
      /* Auto-aktifkan versi baru (SW sudah skipWaiting saat install);
         kirim pesan juga bila SW masih waiting di browser lama. */
      if (worker) { try { worker.postMessage({type:'SKIP_WAITING'}); } catch(e){} }
    }
    if (reg.waiting) promptUpdate(reg.waiting);
    reg.addEventListener('updatefound', function(){
      var nw = reg.installing;
      if (nw) nw.addEventListener('statechange', function(){
        if (nw.state === 'installed' && navigator.serviceWorker.controller) promptUpdate(nw);
      });
    });
  }).catch(function(){});
  var _swReloaded = false;
  navigator.serviceWorker.addEventListener('controllerchange', function(){
    /* SW baru mengambil alih → reload sekali agar halaman pakai versi baru. */
    if (_swReloaded) return; _swReloaded = true; location.reload();
  });
}
document.addEventListener('submit', function(e){
  if(e.defaultPrevented) return;
  var f = e.target;
  if(f && f.hasAttribute && f.hasAttribute('data-loading')){
    var b = f.querySelector('button[type=submit], input[type=submit]');
    if(b && !b.disabled){ b.disabled = true; var o = b.innerHTML; b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Memproses…'; setTimeout(function(){ b.disabled = false; b.innerHTML = o; }, 9000); }
  }
});
function printStruk(elId){ const el=document.getElementById(elId); if(!el) return window.print(); const w=window.open('','_blank','width=320'); w.document.write('<html><head><title>Struk</title><style>body{font-family:monospace;font-size:12px;width:58mm;background:#fff;color:#000}table{width:100%}hr{border-top:1px dashed #000}</style></head><body>'+el.innerHTML+'</body></html>'); w.document.close(); w.focus(); w.print(); }
/* Shortcut "/" fokus ke pencarian navbar (abaikan saat mengetik / modal terbuka) */
(function(){
  function
    isTypingTarget(el)
  {
    if (!el) {
      return false;
    }
    var
      tag = (el.tagName || '').toUpperCase();
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') {
      return true;
    }
    if (el.isContentEditable) {
      return true;
    }
    return false;
  }
  function
    findVisibleSearch()
  {
    var
      list = document.querySelectorAll('.js-global-search');
    var
      i, el;
    for (i = 0; i < list.length; i++) {
      el = list[i];
      if (el && el.offsetParent !== null) {
        return el;
      }
    }
    return list.length ? list[0] : null;
  }
  document.addEventListener('keydown', function(e){
    if (!e || e.key !== '/') {
      return;
    }
    if (e.ctrlKey || e.metaKey || e.altKey) {
      return;
    }
    if (isTypingTarget(e.target)) {
      return;
    }
    if (document.querySelector('.modal.show')) {
      return;
    }
    var
      box = findVisibleSearch();
    if (!box) {
      return;
    }
    e.preventDefault();
    if (box.scrollIntoView) {
      try { box.scrollIntoView({block: 'nearest'}); } catch (err) {}
    }
    box.focus();
    if (box.select) {
      try { box.select(); } catch (err) {}
    }
  });
})();
/* Toggle gelap/terang: simpan localStorage (servicehp-theme), default terang */
(function(){
  var KEY='servicehp-theme';
  function current(){ return document.documentElement.getAttribute('data-bs-theme') || 'light'; }
  function paint(){
    var t=current(); var ic=document.getElementById('theme-icon'); var btn=document.getElementById('theme-toggle');
    if(ic) ic.innerHTML = (t==='dark') ? '<i class="ti ti-sun" style="font-size:18px"></i>' : '<i class="ti ti-moon" style="font-size:18px"></i>';
    if(btn) btn.setAttribute('title', (t==='dark') ? 'Ganti ke mode terang' : 'Ganti ke mode gelap');
    var mc=document.querySelector('meta[name="theme-color"]'); if(mc) mc.setAttribute('content', t==='dark' ? '#1a2233' : '#206bc4');
  }
  document.addEventListener('DOMContentLoaded', function(){
    paint();
    var btn=document.getElementById('theme-toggle'); if(!btn) return;
    btn.addEventListener('click', function(){
      var next = (current()==='dark') ? 'light' : 'dark';
      document.documentElement.setAttribute('data-bs-theme', next);
      try{ localStorage.setItem(KEY, next); }catch(e){}
      paint();
    });
  });
})();
</script>
<script
  id="notif-poll-config"
  data-url="{{ route('notifications.count') }}"
>
  /* Polling badge notifikasi tiap 60 dtk. Tanpa bunyi (keputusan:
     bunyi default MATI agar tidak mengganggu pelayanan toko;
     badge visual saja sudah cukup + bebas masalah autoplay browser). */
  (function(){
    var POLL_MS = 60000;
    function paint(total){
      var badge = document.getElementById('notif-badge');
      if (!badge) {
        return;
      }
      var n = parseInt(total, 10);
      if (isNaN(n) || n <= 0) {
        badge.style.display = 'none';
        badge.textContent = '0';
        return;
      }
      badge.style.display = '';
      badge.textContent = (n > 99) ? '99+' : String(n);
      var bell = document.getElementById('notif-bell');
      if (bell) {
        bell.setAttribute('title', n + ' notifikasi perlu perhatian');
      }
    }
    function refresh(){
      var cfg = document.getElementById('notif-poll-config');
      var url = cfg ? cfg.getAttribute('data-url') : '/notifications/count';
      fetch(url, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
      }).then(function(res){
        if (!res.ok) {
          return null;
        }
        return res.json();
      }).then(function(data){
        if (data && typeof data.total !== 'undefined') {
          paint(data.total);
        }
      }).catch(function(){ /* abaikan: badge tetap tampilkan nilai terakhir */ });
    }
    document.addEventListener('DOMContentLoaded', function(){
      refresh();
      setInterval(refresh, POLL_MS);
    });
  })();
</script>
<button type="button" id="pwa-install-btn" class="btn btn-primary no-print" style="display:none;position:fixed;left:16px;bottom:16px;z-index:1070;border-radius:999px;padding:.7rem 1.1rem;box-shadow:0 8px 24px rgba(15,95,208,.45)"><i class="ti ti-device-mobile me-1"></i>Install Aplikasi</button>
@stack('js')
<script>
/* Prompt install PWA (Android/Chrome): tampilkan tombol hanya bila browser mengizinkan. */
(function(){
  var btn = document.getElementById('pwa-install-btn');
  var deferred = null;
  window.addEventListener('beforeinstallprompt', function(e){
    e.preventDefault();
    deferred = e;
    if (btn) btn.style.display = '';
  });
  if (btn) btn.addEventListener('click', function(){
    if (!deferred) return;
    deferred.prompt();
    deferred.userChoice.finally(function(){ deferred = null; btn.style.display = 'none'; });
  });
  window.addEventListener('appinstalled', function(){ if (btn) btn.style.display = 'none'; });
})();
</script>
</body>
</html>
