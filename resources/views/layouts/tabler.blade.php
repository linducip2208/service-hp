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
  @include('layouts.sidebar')
  {{-- Topbar --}}
  <header class="navbar navbar-expand-md d-print-none no-print corporate-topbar">
    <div class="container-xl">
      <button type="button" id="sidebar-mini-toggle" class="btn btn-icon btn-ghost-secondary d-none d-lg-inline-flex me-1" title="Rampingkan sidebar" aria-label="Rampingkan sidebar">
        <i class="ti ti-layout-sidebar-left-collapse" style="font-size:18px"></i>
      </button>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar-menu" aria-label="Menu">
        <span class="navbar-toggler-icon"></span>
      </button>
      @if(isset($allStores) && $allStores->count())
      <form method="POST" action="#" class="nav-item me-2 d-flex align-items-center" onsubmit="return false" title="Toko aktif">
        <select class="form-select form-select-sm" style="max-width:180px" onchange="fetch('{{ url('/store') }}/'+this.value,{method:'POST',headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}'}}).then(()=>location.reload())" aria-label="Toko aktif">
          @foreach($allStores as $s)
            <option value="{{ $s->id }}" {{ session('active_store_id')==$s->id?'selected':'' }}>{{ $s->name }}</option>
          @endforeach
        </select>
      </form>
      @endif
      <form method="GET" action="{{ route('search') }}" class="d-flex ms-0 me-auto flex-fill" role="search" style="max-width:560px">
        <div class="input-icon w-100">
          <span class="input-icon-addon"><i class="ti ti-search"></i></span>
          <input type="text" name="q" value="{{ request('q') }}" class="form-control form-control-sm js-global-search" id="global-search-top" placeholder="Cari invoice / tiket / IMEI / pelanggan / produk…  ( / atau Ctrl+K )" autocomplete="off">
        </div>
      </form>
      <div class="navbar-nav flex-row order-md-last">
        <div class="nav-item me-1 d-flex align-items-center">
          <a href="{{ route('dashboard') }}" id="notif-bell" class="btn btn-icon btn-ghost-secondary position-relative" title="Notifikasi: tiket siap diambil, tiket terlambat, booking menunggu" aria-label="Notifikasi">
            <span aria-hidden="true"><i class="ti ti-bell" style="font-size:18px"></i></span>
            <span id="notif-badge" class="badge bg-red position-absolute top-0 start-100 translate-middle" style="display:none">0</span>
          </a>
        </div>
        <div class="nav-item me-1 d-flex align-items-center">
          <button type="button" id="theme-toggle" class="btn btn-icon btn-ghost-secondary" title="Mode gelap/terang" aria-label="Mode gelap/terang">
            <span id="theme-icon"><i class="ti ti-moon" style="font-size:18px"></i></span>
          </button>
        </div>
        <div class="nav-item dropdown">
          <a href="#" class="nav-link d-flex lh-1 text-reset p-0" data-bs-toggle="dropdown">
            <span class="avatar avatar-sm">{{ strtoupper(mb_substr(auth()->user()->name ?? 'A', 0, 1)) }}</span>
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
    </div>
  </header>
  <div class="page-wrapper">
    <div class="page-body">
      <div class="container-xl">
        <div class="toast-container position-fixed bottom-0 end-0 p-3 d-print-none" style="z-index:1090">
        @foreach(['success' => 'bg-success text-white', 'warning' => 'bg-warning', 'error' => 'bg-danger text-white'] as $key => $cls)
            @if(session($key))
            <div class="toast show align-items-center {{ $cls }} border-0 mb-2" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">{{ session($key) }}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Tutup"></button>
                </div>
            </div>
            @endif
        @endforeach
        </div>
        @if($errors->any())<div class="alert alert-danger alert-dismissible d-print-none no-print"><div>{{ $errors->first() }}@if($errors->count()>1) <span class="text-secondary">(+{{ $errors->count()-1 }} lainnya)</span>@endif</div><a class="btn-close" data-bs-dismiss="alert"></a></div>@endif
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
/* Toast otomatis hilang 6 detik (tetap bisa ditutup manual). */
(function(){
  document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('.toast-container .toast.show').forEach(function(t){
      setTimeout(function(){ t.classList.remove('show'); setTimeout(function(){ t.remove(); }, 300); }, 6000);
    });
  });
})();
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
<script>
/* Sidebar: cari menu, favorit, terakhir dibuka, mini-mode, ingat grup terbuka, Ctrl+K. */
(function(){
  var FAV='shp_nav_fav', REC='shp_nav_rec', OPEN='shp_nav_open', MINI='shp_sidebar_mini';
  function read(k, fb){ try{ var v=JSON.parse(localStorage.getItem(k)); return v==null?fb:v; }catch(e){ return fb; } }
  function write(k, v){ try{ localStorage.setItem(k, JSON.stringify(v)); }catch(e){} }
  document.addEventListener('DOMContentLoaded', function(){
    var nav = document.getElementById('side-nav');
    if(!nav) return;
    /* Mini mode */
    if(read(MINI, false)) document.body.classList.add('sidebar-mini');
    var miniBtn = document.getElementById('sidebar-mini-toggle');
    if(miniBtn) miniBtn.addEventListener('click', function(){
      var on = document.body.classList.toggle('sidebar-mini');
      write(MINI, on);
    });
    /* Grup terbuka tersimpan */
    var open = read(OPEN, []);
    if(open.length){
      nav.querySelectorAll('.side-group').forEach(function(g){
        var name = g.getAttribute('data-group');
        if(open.indexOf(name) >= 0){
          var menu = g.querySelector('.dropdown-menu');
          var link = g.querySelector('.dropdown-toggle');
          if(menu) menu.classList.add('show');
          if(link) link.setAttribute('aria-expanded','true');
        }
      });
    }
    nav.querySelectorAll('.side-group > .dropdown-toggle').forEach(function(link){
      link.addEventListener('click', function(){
        var g = link.closest('.side-group');
        var name = g ? g.getAttribute('data-group') : '';
        setTimeout(function(){
          var menu = g ? g.querySelector('.dropdown-menu') : null;
          var isOpen = menu && menu.classList.contains('show');
          var cur = read(OPEN, []);
          if(isOpen && cur.indexOf(name) < 0){ cur.push(name); write(OPEN, cur); }
          if(!isOpen){ write(OPEN, cur.filter(function(x){ return x !== name; })); }
        }, 50);
      });
    });
    /* Favorit */
    function paintFav(){
      var favs = read(FAV, []);
      nav.querySelectorAll('.side-fav').forEach(function(btn){
        btn.classList.toggle('on', favs.indexOf(btn.getAttribute('data-route')) >= 0);
      });
      var box = document.getElementById('nav-favorites');
      var list = document.getElementById('nav-favorites-list');
      if(!box || !list) return;
      list.innerHTML = '';
      var shown = 0;
      nav.querySelectorAll('.side-group .side-item > a.dropdown-item').forEach(function(a){
        if(favs.indexOf(a.getAttribute('data-route')) < 0) return;
        shown++;
        var c = a.cloneNode(true);
        c.classList.remove('active');
        list.appendChild(c);
      });
      box.style.display = shown ? '' : 'none';
    }
    nav.addEventListener('click', function(e){
      var btn = e.target.closest('.side-fav');
      if(btn){
        e.preventDefault(); e.stopPropagation();
        var r = btn.getAttribute('data-route');
        var favs = read(FAV, []);
        var i = favs.indexOf(r);
        if(i >= 0) favs.splice(i, 1); else favs.push(r);
        write(FAV, favs); paintFav(); return;
      }
      var a = e.target.closest('a.dropdown-item[href]');
      if(a && a.getAttribute('data-route')){
        var recs = read(REC, []).filter(function(x){ return x.route !== a.getAttribute('data-route'); });
        recs.unshift({route: a.getAttribute('data-route'), title: a.getAttribute('data-title') || a.textContent.trim(), url: a.getAttribute('href')});
        write(REC, recs.slice(0, 5)); paintRec();
      }
    });
    function paintRec(){
      var recs = read(REC, []);
      var box = document.getElementById('nav-recents');
      var list = document.getElementById('nav-recents-list');
      if(!box || !list) return;
      list.innerHTML = '';
      recs.forEach(function(r){
        var a = document.createElement('a');
        a.className = 'dropdown-item'; a.href = r.url;
        a.innerHTML = '<i class="ti ti-history me-1"></i><span class="sidebar-label"></span>';
        a.querySelector('span').textContent = r.title;
        list.appendChild(a);
      });
      box.style.display = recs.length ? '' : 'none';
    }
    paintFav(); paintRec();
    /* Cari menu */
    var q = document.getElementById('menu-search');
    if(q) q.addEventListener('input', function(){
      var needle = q.value.trim().toLowerCase();
      nav.querySelectorAll('.side-group').forEach(function(g){
        var hits = 0;
        g.querySelectorAll('.side-item').forEach(function(it){
          var ok = !needle || (it.getAttribute('data-label') || '').indexOf(needle) >= 0;
          it.style.display = ok ? '' : 'none';
          if(ok) hits++;
        });
        g.style.display = (!needle || hits) ? '' : 'none';
        var menu = g.querySelector('.dropdown-menu');
        if(menu && needle && hits) menu.classList.add('show');
      });
      ['nav-favorites','nav-recents'].forEach(function(id){
        var el = document.getElementById(id);
        if(el) el.style.display = needle ? 'none' : el.style.display;
      });
    });
  });
  /* Ctrl+K / Cmd+K fokus ke pencarian global (slash tetap didukung kode lama). */
  document.addEventListener('keydown', function(e){
    if((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')){
      var box = document.getElementById('global-search-top') || document.querySelector('.js-global-search');
      if(!box) return;
      e.preventDefault();
      box.focus(); if(box.select){ try{ box.select(); }catch(err){} }
    }
  });
})();
</script>
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
