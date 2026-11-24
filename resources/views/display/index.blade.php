<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta http-equiv="refresh" content="30" />
  <title>Layar Antrean Service — {{ $activeStore->name ?? 'Semua Toko' }}</title>
  <link href="/vendor/tabler/tabler.min.css" rel="stylesheet" />
  <style>
    body {
      background: #0b1220;
      color: #e8eefc;
      min-height: 100vh;
      padding-bottom: 3.5rem;
    }
    .tv-header {
      border-bottom: 1px solid #26314d;
    }
    .tv-clock {
      font-variant-numeric: tabular-nums;
      font-size: 2rem;
      font-weight: 800;
      line-height: 1;
    }
    .tv-date {
      color: #93a1c4;
    }
    .ticket-card {
      background: #141d33;
      border: 1px solid #2a3757;
      border-radius: 1rem;
      padding: 1rem 1.25rem;
    }
    .ticket-no {
      font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
      font-size: 1.9rem;
      font-weight: 800;
      letter-spacing: .02em;
    }
    .ticket-meta {
      color: #93a1c4;
      font-size: .95rem;
    }
    .ready-card {
      background: #0d3320;
      border: 2px solid #2fb344;
      animation: pulse-ready 1.6s ease-in-out infinite;
    }
    .ready-card .ticket-no {
      color: #b6f3c3;
    }
    .urgent-card {
      border: 2px solid #ff453a;
    }
    .urgent-badge {
      font-size: 1.05rem;
      animation: blink-urgent 1s step-start infinite;
    }
    @keyframes blink-urgent {
      0%, 100% { opacity: 1; }
      50% { opacity: .3; }
    }
    .ready-badge {
      font-size: 1.05rem;
    }
    @keyframes pulse-ready {
      0%, 100% { box-shadow: 0 0 0 0 rgba(47, 179, 68, .55); }
      50% { box-shadow: 0 0 0 12px rgba(47, 179, 68, 0); }
    }
    .store-filter a {
      color: #93a1c4;
      text-decoration: none;
      border: 1px solid #2a3757;
      border-radius: 999px;
      padding: .15rem .8rem;
      font-size: .85rem;
    }
    .store-filter a.active {
      color: #fff;
      background: #206bc4;
      border-color: #206bc4;
    }
    @media (min-width: 1400px) {
      .ticket-no { font-size: 2.3rem; }
    }
    #ready-alert-banner {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      z-index: 1050;
      display: none;
      text-align: center;
      font-size: 1.5rem;
      font-weight: 800;
      padding: .9rem 3rem .9rem 1rem;
      background: #2fb344;
      color: #06210f;
      border-bottom: 3px solid #b6f3c3;
    }
    #ready-alert-banner.show { display: block; animation: banner-flash 1s step-start infinite; }
    @keyframes banner-flash {
      0%, 100% { background: #2fb344; color: #06210f; }
      50% { background: #fff200; color: #111; }
    }
    #ready-alert-banner .close-btn {
      position: absolute;
      right: .75rem;
      top: 50%;
      transform: translateY(-50%);
      border: 0;
      border-radius: 999px;
      width: 2rem;
      height: 2rem;
      font-weight: 800;
      cursor: pointer;
      background: rgba(0,0,0,.2);
      color: inherit;
    }
    #sound-hint {
      position: fixed;
      bottom: 3.5rem;
      right: 1rem;
      z-index: 1050;
      background: #206bc4;
      color: #fff;
      border: 0;
      border-radius: 999px;
      padding: .5rem 1rem;
      font-weight: 700;
      cursor: pointer;
      box-shadow: 0 4px 16px rgba(0,0,0,.4);
    }
    .queue-take {
      background: #141d33;
      border: 1px dashed #3b4f83;
      border-radius: 1rem;
      padding: 1rem 1.25rem;
    }
    .queue-big {
      font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
      font-size: 4rem;
      font-weight: 900;
      letter-spacing: .04em;
      line-height: 1;
      color: #ffd60a;
    }
    @media print {
      body { background: #fff; color: #000; }
      body * { visibility: hidden; }
      #queue-slip, #queue-slip * { visibility: visible; }
      #queue-slip {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        margin: 0 auto;
        width: 72mm;
        border: 0;
        color: #000;
      }
      #queue-slip .no-print { display: none !important; }
    }
    /* Panel kasir lokal: panggil berikutnya (tanpa auth, per perangkat). */
    #call-panel {
      position: fixed;
      bottom: 3.5rem;
      left: 1rem;
      z-index: 1050;
      display: flex;
      gap: .5rem;
      align-items: center;
      flex-wrap: wrap;
      max-width: calc(100vw - 2rem);
    }
    #call-next-btn {
      background: #f59f00;
      color: #1a1000;
      border: 0;
      border-radius: 999px;
      padding: .55rem 1.1rem;
      font-weight: 800;
      cursor: pointer;
      box-shadow: 0 4px 16px rgba(0,0,0,.4);
    }
    #call-next-btn:disabled {
      opacity: .45;
      cursor: not-allowed;
    }
    #call-reset-btn {
      background: transparent;
      color: #93a1c4;
      border: 1px solid #2a3757;
      border-radius: 999px;
      padding: .45rem .9rem;
      font-weight: 700;
      cursor: pointer;
    }
    #call-status {
      color: #93a1c4;
      font-size: .8rem;
    }
    .ticket-card.called-now {
      outline: 4px solid #fff200;
      outline-offset: 2px;
    }
    .ticket-card.already-called {
      opacity: .75;
    }
    /* Ticker promo aktif — marquee murni CSS, tanpa lib. */
    #promo-ticker {
      position: fixed;
      left: 0;
      right: 0;
      bottom: 0;
      z-index: 1040;
      background: #3b0a0a;
      border-top: 2px solid #f59f00;
      color: #ffe8c2;
      overflow: hidden;
      white-space: nowrap;
      height: 2.75rem;
      display: flex;
      align-items: center;
    }
    #promo-ticker .ticker-label {
      flex: 0 0 auto;
      background: #f59f00;
      color: #1a1000;
      font-weight: 800;
      font-size: .85rem;
      padding: .35rem .9rem;
      margin-right: 1rem;
      border-radius: 0 .75rem .75rem 0;
      z-index: 2;
    }
    #promo-ticker .ticker-viewport {
      flex: 1 1 auto;
      overflow: hidden;
    }
    #promo-ticker .ticker-track {
      display: inline-block;
      padding-left: 100%;
      animation: promo-ticker-scroll 30s linear infinite;
      font-weight: 700;
      font-size: 1rem;
    }
    #promo-ticker:hover .ticker-track {
      animation-play-state: paused;
    }
    @keyframes promo-ticker-scroll {
      0% {
        transform: translateX(0);
      }
      100% {
        transform: translateX(-100%);
      }
    }
    @media (prefers-reduced-motion: reduce) {
      #promo-ticker .ticker-track {
        animation: none;
        padding-left: 0;
        white-space: normal;
      }
    }
  </style>
</head>
<body>
  <div id="ready-alert-banner" role="alert">
    <span id="ready-alert-text">🔔 Ada HP siap diambil! Silakan ke kasir.</span>
    <button type="button" class="close-btn" id="ready-alert-close" aria-label="Tutup">✕</button>
  </div>
  <button type="button" id="sound-hint" title="Klik sekali agar bunyi panggilan TV diizinkan browser">🔊 Aktifkan Suara</button>
  {{-- Kontrol kasir lokal (tanpa login): murni JS + localStorage per perangkat. --}}
  <div id="call-panel">
    <button type="button" id="call-next-btn">📢 Panggil Berikutnya</button>
    <button type="button" id="call-reset-btn" title="Ulangi dari awal (hapus tanda sudah dipanggil di perangkat ini)">↺ Ulangi</button>
    <span id="call-status"></span>
  </div>
  <header class="tv-header">
    <div class="container-xl d-flex flex-wrap align-items-center justify-content-between gap-3 py-3">
      <div>
        <div class="text-uppercase small tv-date">🔧 {{ config('app.name', 'ServiceHP') }} — Layar Antrean</div>
        <h1 class="mb-0">
          {{ $activeStore->name ?? 'Semua Toko Aktif' }}
        </h1>
        <div class="tv-date small">
          Auto-refresh 30 detik • Hanya nomor tiket + status
        </div>
      </div>
      <div class="text-end">
        <div class="tv-clock" id="tv-clock">--:--:--</div>
        <div class="tv-date" id="tv-date">Memuat tanggal…</div>
      </div>
    </div>
    @if ($stores->count() > 1)
      <div class="container-xl pb-3">
        <div class="store-filter d-flex flex-wrap gap-2">
          <a href="{{ route('display') }}" class="{{ $storeId ? '' : 'active' }}">
            Semua
          </a>
          @foreach ($stores as $s)
            <a
              href="{{ route('display', ['store' => $s->id]) }}"
              class="{{ (int) $storeId === (int) $s->id ? 'active' : '' }}"
            >
              {{ $s->name }}
            </a>
          @endforeach
        </div>
      </div>
    @endif
  </header>

  <main class="container-xl py-4">
    {{-- Ambil nomor antrean walk-in (publik, tanpa login) --}}
    @php
      $takenRaw = request('taken', '');
      $takenNo = is_string($takenRaw) && preg_match('/^A\d{3,4}$/', $takenRaw) ? $takenRaw : null;
    @endphp
    <div class="queue-take mb-4 text-center">
      <div class="text-uppercase small tv-date mb-2">🎫 Antrean Walk-In — tanpa login</div>
      @if ($takenNo)
        <div id="queue-slip">
          <div class="tv-date small">{{ $activeStore->name ?? 'Semua Toko Aktif' }} • {{ now()->format('d/m/Y H:i') }}</div>
          <div class="queue-big my-2">{{ $takenNo }}</div>
          <div class="ticket-meta mb-3">Simpan nomor ini &amp; tunggu panggilan di layar.<br />Nomor reset tiap hari (A001…).</div>
          <div class="no-print d-flex justify-content-center gap-2 flex-wrap">
            <button type="button" class="btn btn-light" onclick="window.print()">🖨️ Cetak Struk Kecil</button>
            <a href="{{ route('display', $storeId ? ['store' => $storeId] : []) }}" class="btn btn-outline-light">Ambil Lagi / Tutup</a>
          </div>
        </div>
      @else
        <form action="{{ route('queue.take') }}" method="POST" class="d-flex justify-content-center">
          @csrf
          @if ($storeId)
            <input type="hidden" name="store" value="{{ $storeId }}" />
          @endif
          <button type="submit" class="btn btn-warning btn-lg fw-bold px-4">🎫 Ambil Nomor Antrean</button>
        </form>
        <div class="tv-date small mt-2">1 klik = 1 nomor (A001, A002, …) • Reset otomatis tiap hari</div>
      @endif
    </div>

    <div class="row g-3">
      <div class="col-lg-6">
        <h2 class="mb-3">✅ Siap Diambil ({{ $ready->count() }})</h2>
        @forelse ($ready as $t)
          <div class="ticket-card ready-card mb-3 {{ ($t->priority ?? '') === 'mendesak' ? 'urgent-card' : '' }}" data-ticket="{{ $t->ticket_no }}">
            <div class="d-flex justify-content-between align-items-center gap-2">
              <span class="ticket-no">{{ $t->ticket_no }}</span>
              <span class="d-flex gap-2">
                @if (($t->priority ?? '') === 'mendesak')
                  <span class="badge bg-red urgent-badge">⚡ MENDESAK</span>
                @endif
                <span class="badge bg-green ready-badge">SIAP DIAMBIL</span>
              </span>
            </div>
            <div class="ticket-meta mt-1">
              {{ $t->store_name }}
              • Update: {{ \Carbon\Carbon::parse($t->updated_at)->format('d/m H:i') }}
              @if ($t->estimated_done_at)
                • Estimasi: {{ \Carbon\Carbon::parse($t->estimated_done_at)->format('d/m H:i') }}
              @endif
            </div>
          </div>
        @empty
          <div class="ticket-card">
            <div class="ticket-meta">Belum ada tiket siap diambil.</div>
          </div>
        @endforelse
      </div>

      <div class="col-lg-6">
        <h2 class="mb-3">🛠️ Dalam Proses ({{ $process->count() }})</h2>
        @forelse ($process as $t)
          <div class="ticket-card mb-3 {{ ($t->priority ?? '') === 'mendesak' ? 'urgent-card' : '' }}">
            <div class="d-flex justify-content-between align-items-center gap-2">
              <span class="ticket-no">{{ $t->ticket_no }}</span>
              <span class="d-flex gap-2">
                @if (($t->priority ?? '') === 'mendesak')
                  <span class="badge bg-red urgent-badge">⚡ MENDESAK</span>
                @endif
                <span class="badge bg-blue-lt">{{ str_replace('_', ' ', $t->status) }}</span>
              </span>
            </div>
            <div class="ticket-meta mt-1">
              {{ $t->store_name }}
              • Masuk: {{ \Carbon\Carbon::parse($t->created_at)->format('d/m H:i') }}
            </div>
          </div>
        @empty
          <div class="ticket-card">
            <div class="ticket-meta">Tidak ada antrean aktif.</div>
          </div>
        @endforelse
      </div>
    </div>

    <p class="tv-date small mt-4 mb-0">
      Demi privasi pelanggan, layar ini TIDAK menampilkan nama, nomor HP, biaya, keluhan, IMEI, maupun teknisi.
    </p>
  </main>

  {{-- Ticker promo aktif di bawah display TV --}}
  @if (($promos ?? collect())->count())
    <div
      id="promo-ticker"
      role="marquee"
      aria-label="Promo aktif"
    >
      <span class="ticker-label">
        🎁 PROMO
      </span>
      <div class="ticker-viewport">
        <span class="ticker-track">
          @foreach ($promos as $promo)
            <span>
              🎁 {{ $promo->name }} [{{ $promo->code }}]
              @if (($promo->type ?? '') === 'diskon_persen' && (float) $promo->value > 0)
                — diskon {{ rtrim(rtrim(number_format((float) $promo->value, 2), '0'), '.') }}%
              @elseif (($promo->type ?? '') === 'diskon_rupiah' && (float) $promo->value > 0)
                — potongan Rp {{ number_format((float) $promo->value, 0, ',', '.') }}
              @endif
              @if (! empty($promo->end_date))
                • s.d. {{ \Carbon\Carbon::parse($promo->end_date)->format('d/m/Y') }}
              @endif
              @if ((int) ($promo->quota ?? 0) > 0)
                • sisa {{ (int) $promo->quota - (int) ($promo->used ?? 0) }}
              @endif
            </span>
            @if (! $loop->last)
              <span aria-hidden="true">
                &nbsp;&nbsp;✦&nbsp;&nbsp;
              </span>
            @endif
          @endforeach
        </span>
      </div>
    </div>
  @endif

  <script>
    function tickClock() {
      var now = new Date();
      var pad = function (n) { return String(n).padStart(2, '0'); };
      var clock = document.getElementById('tv-clock');
      var date = document.getElementById('tv-date');
      if (clock) {
        clock.textContent = pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
      }
      if (date) {
        date.textContent = now.toLocaleDateString('id-ID', {
          weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
        });
      }
    }
    tickClock();
    setInterval(tickClock, 1000);

    // Bunyi panggilan TV: chime 3x (Web Audio, tanpa file) + flash banner
    // saat jumlah siap_diambil BERTAMBAH vs refresh sebelumnya (localStorage).
    (function () {
      var currentReady = {{ $ready->count() }};
      var storeKey = '{{ $storeId ? "store_" . $storeId : "all" }}';
      var lsKey = 'tv_ready_count_' + storeKey;
      var soundKey = 'tv_sound_enabled';
      var prevRaw = null;
      try { prevRaw = localStorage.getItem(lsKey); } catch (e) { prevRaw = null; }
      var prev = prevRaw === null ? null : parseInt(prevRaw, 10);
      if (isNaN(prev)) { prev = null; }

      var banner = document.getElementById('ready-alert-banner');
      var closeBtn = document.getElementById('ready-alert-close');
      var soundBtn = document.getElementById('sound-hint');
      if (closeBtn && banner) {
        closeBtn.addEventListener('click', function () { banner.classList.remove('show'); });
      }

      var audioCtx = null;
      function ensureCtx() {
        try {
          var AC = window.AudioContext || window.webkitAudioContext;
          if (!AC) return null;
          if (!audioCtx) { audioCtx = new AC(); }
          if (audioCtx.state === 'suspended') { audioCtx.resume(); }
          return audioCtx;
        } catch (e) { return null; }
      }
      // Klik tombol = izin browser untuk bunyi (autoplay policy).
      if (soundBtn) {
        try {
          if (localStorage.getItem(soundKey) === '1') { soundBtn.style.display = 'none'; }
        } catch (e) {}
        soundBtn.addEventListener('click', function () {
          ensureCtx();
          try { localStorage.setItem(soundKey, '1'); } catch (e) {}
          soundBtn.style.display = 'none';
          chime(1); // tes bunyi sekali
        });
      }

      function tone(ctx, freq, startAt, dur) {
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.value = freq;
        gain.gain.setValueAtTime(0.0001, startAt);
        gain.gain.exponentialRampToValueAtTime(0.5, startAt + 0.03);
        gain.gain.exponentialRampToValueAtTime(0.0001, startAt + dur);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start(startAt);
        osc.stop(startAt + dur + 0.05);
      }
      function chime(times) {
        var ctx = ensureCtx();
        if (!ctx) return;
        times = times || 3;
        for (var i = 0; i < times; i++) {
          var base = ctx.currentTime + i * 1.0;
          tone(ctx, 880, base, 0.35);        // ding
          tone(ctx, 659.25, base + 0.4, 0.45); // dong
        }
      }

      // Simpan jumlah terbaru untuk refresh berikutnya.
      try { localStorage.setItem(lsKey, String(currentReady)); } catch (e) {}

      // Hanya bunyi jika jumlah BERTAMBAH (bukan refresh pertama / berkurang / sama).
      if (prev !== null && currentReady > prev) {
        if (banner) { banner.classList.add('show'); }
        // Coba bunyi otomatis; jika browser memblokir (belum ada interaksi),
        // banner tetap tampil dan tombol suara tetap terlihat.
        try { chime(3); } catch (e) {}
      }

      // Tombol kasir lokal "Panggil Berikutnya" (tanpa auth, per perangkat).
      // Urut sesuai list siap_diambil yang dirender; tandai sudah dipanggil
      // di localStorage; RESET tiap load (mulai segar setiap refresh TV 30 dtk).
      var readyNos = @json($ready->pluck('ticket_no')->values());
      var calledKey = 'tv_called_' + storeKey;
      var bannerText = document.getElementById('ready-alert-text');
      var callBtn = document.getElementById('call-next-btn');
      var callResetBtn = document.getElementById('call-reset-btn');
      var callStatus = document.getElementById('call-status');
      // Reset tiap load: ronde panggilan selalu mulai dari awal.
      try { localStorage.removeItem(calledKey); } catch (e) {}
      var calledList = [];
      try {
        var raw = localStorage.getItem(calledKey);
        calledList = raw ? JSON.parse(raw) : [];
        if (!Array.isArray(calledList)) { calledList = []; }
      } catch (e) { calledList = []; }

      function saveCalled() {
        try { localStorage.setItem(calledKey, JSON.stringify(calledList)); } catch (e) {}
      }
      function refreshCallUI(currentNo) {
        document.querySelectorAll('.ticket-card.ready-card').forEach(function (el) {
          var no = el.getAttribute('data-ticket');
          el.classList.toggle('already-called', calledList.indexOf(no) !== -1);
          el.classList.toggle('called-now', no === currentNo);
        });
        if (callStatus) {
          callStatus.textContent = calledList.length
            ? 'Dipanggil ' + calledList.length + '/' + readyNos.length
            : (readyNos.length ? 'Siap: ' + readyNos.length : '');
        }
        if (callBtn) { callBtn.disabled = readyNos.length === 0; }
      }
      function stopAutoRefresh() {
        // Cegah meta-refresh 30 dtk menghapus ronde panggilan saat kasir memanggil.
        var m = document.querySelector('meta[http-equiv="refresh"]');
        if (m) { m.remove(); }
      }
      if (callBtn) {
        callBtn.addEventListener('click', function () {
          ensureCtx();
          stopAutoRefresh();
          var next = null;
          for (var i = 0; i < readyNos.length; i++) {
            if (calledList.indexOf(readyNos[i]) === -1) { next = readyNos[i]; break; }
          }
          if (!next) {
            if (bannerText) { bannerText.textContent = '✅ Semua tiket siap diambil sudah dipanggil.'; }
            if (banner) { banner.classList.add('show'); }
            if (callStatus) { callStatus.textContent = 'Semua sudah dipanggil (' + readyNos.length + ')'; }
            try { chime(1); } catch (e) {}
            return;
          }
          calledList.push(next);
          saveCalled();
          refreshCallUI(next);
          if (bannerText) { bannerText.textContent = '📢 Panggilan: ' + next + ' — silakan ke kasir!'; }
          if (banner) { banner.classList.add('show'); }
          try { chime(3); } catch (e) {}
          var el = document.querySelector('.ticket-card.ready-card[data-ticket="' + next + '"]');
          if (el && el.scrollIntoView) { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
        });
      }
      if (callResetBtn) {
        callResetBtn.addEventListener('click', function () {
          calledList = [];
          saveCalled();
          refreshCallUI(null);
          if (banner) { banner.classList.remove('show'); }
        });
      }
      refreshCallUI(null);
    })();
  </script>
</body>
</html>
