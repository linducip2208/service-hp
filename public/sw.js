/* ServiceHP Service Worker — offline hardening (tanpa backend).
 * Strategi:
 *  - PRECACHE (install, versioned STATIC_V): halaman kunci + aset lokal.
 *    Halaman (/, /login, /track, /booking) di-cache best-effort satu-per-satu
 *    agar redirect auth saat install TIDAK menggagalkan seluruh precache.
 *  - Navigasi HTML: network-first → cache → /offline.html.
 *  - Aset same-origin (css/js/img/font/manifest): stale-while-revalidate.
 *  - CDN runtime (jsdelivr/unpkg/cdnjs: tabler, jsbarcode, chart.js,
 *    html2canvas, qrcodejs): cache-first → network → put ke CDN_V.
 *  - Aman untuk login: hanya request GET yang di-cache; POST/PUT/DELETE
 *    dan non-http(s) (chrome-extension, ws) SELALU diteruskan ke network
 *    tanpa disentuh cache.
 *  - Update: skipWaiting() saat install + clients.claim() saat activate +
 *    listener pesan 'SKIP_WAITING' agar halaman bisa memicu update manual.
 */
var STATIC_V = 'servicehp-static-v4';
var CDN_V = 'servicehp-cdn-v4';
var OFFLINE_URL = '/offline.html';

/* Aset lokal kecil yang wajib ada — gagal satu = install gagal (disengaja,
 * karena ini file statis public yang selalu tersedia). */
var CORE_ASSETS = [
  OFFLINE_URL,
  '/manifest.webmanifest',
  '/js/printer.js',
  '/css/corporate.css',
  '/vendor/fonts/fonts.css',
  '/vendor/tabler/tabler.min.css',
  '/vendor/tabler/tabler.min.js',
  '/vendor/icons/tabler-icons.min.css',
  '/icons/icon.svg',
  '/icons/icon-192.png',
  '/icons/icon-512.png',
  '/favicon.ico'
];

/* Halaman kunci — best-effort (add satu-per-satu, kegagalan diabaikan agar
 * redirect login/auth tidak menggagalkan install). */
var KEY_PAGES = ['/', '/login', '/track', '/booking'];

var CDN_HOSTS = [
  'cdn.jsdelivr.net',
  'unpkg.com',
  'cdnjs.cloudflare.com',
  'fonts.googleapis.com',
  'fonts.gstatic.com'
];

function isCdn(url) {
  return CDN_HOSTS.indexOf(url.hostname) !== -1;
}

function isCacheable(req) {
  if (req.method !== 'GET') return false;
  var url = new URL(req.url);
  return url.protocol === 'http:' || url.protocol === 'https:';
}

self.addEventListener('install', function (e) {
  e.waitUntil(
    caches.open(STATIC_V).then(function (cache) {
      return cache.addAll(CORE_ASSETS).then(function () {
        /* Precache halaman kunci satu-per-satu; abaikan kegagalan. */
        return Promise.all(
          KEY_PAGES.map(function (p) { return cache.add(p).catch(function () {}); })
        );
      });
    }).then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (e) {
  e.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.map(function (k) {
        if (k !== STATIC_V && k !== CDN_V) return caches.delete(k);
        return Promise.resolve(false);
      }));
    }).then(function () { return self.clients.claim(); })
  );
});

/* Halaman bisa mengirim {type:'SKIP_WAITING'} untuk memaksa update. */
self.addEventListener('message', function (e) {
  if (e.data && e.data.type === 'SKIP_WAITING') self.skipWaiting();
});

self.addEventListener('fetch', function (e) {
  var req = e.request;

  /* Jangan sentuh non-GET (POST login/track/booking, dsb.) & non-http(s). */
  if (!isCacheable(req)) {
    e.respondWith(fetch(req));
    return;
  }

  var url = new URL(req.url);

  /* 1) CDN runtime: cache-first. */
  if (isCdn(url)) {
    e.respondWith(
      caches.open(CDN_V).then(function (cache) {
        return cache.match(req).then(function (hit) {
          if (hit) return hit;
          return fetch(req).then(function (res) {
            if (res && (res.status === 200 || res.type === 'opaque')) {
              cache.put(req, res.clone());
            }
            return res;
          });
        });
      })
    );
    return;
  }

  /* 2) Navigasi dokumen: network-first → cache → offline fallback. */
  if (req.mode === 'navigate' ||
      (req.headers.get('accept') || '').indexOf('text/html') !== -1) {
    e.respondWith(
      fetch(req).then(function (res) {
        var copy = res.clone();
        caches.open(STATIC_V).then(function (cache) { cache.put(req, copy); });
        return res;
      }).catch(function () {
        return caches.match(req).then(function (hit) {
          if (hit) return hit;
          return caches.match(OFFLINE_URL);
        });
      })
    );
    return;
  }

  /* 3) Aset same-origin: stale-while-revalidate. */
  if (url.origin === self.location.origin) {
    e.respondWith(
      caches.match(req).then(function (hit) {
        var net = fetch(req).then(function (res) {
          if (res && res.status === 200) {
            var copy = res.clone();
            caches.open(STATIC_V).then(function (cache) { cache.put(req, copy); });
          }
          return res;
        }).catch(function () { return hit; });
        return hit || net;
      })
    );
    return;
  }

  /* 4) Selain itu: network-first, fallback cache. */
  e.respondWith(fetch(req).catch(function () { return caches.match(req); }));
});
