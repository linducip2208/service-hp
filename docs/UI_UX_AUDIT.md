# ServiceHP — UI/UX Audit (Fase 1)

## Aset: lokal vs CDN

Lokal (diprecache SW): `corporate.css`, `printer.js` (PrintHub), `sw.js` v3, `offline.html`,
manifest, ikon PNG 192/512 + maskable + apple-touch + favicon.
CDN (diizinkan SW, cache-first): Tabler CSS/JS (beta20, **icons `latest` tak dipin**),
Google Fonts, Chart.js 4.4.1, JsBarcode 3.11.6, qrcodejs, html2canvas.
**Vite/Tailwind build ada tapi tak dipakai siapa pun** (`resources/css/app.css`, build output yatim) —
putuskan: pakai `@vite` + kurangi CDN, atau hapus agar tak membingungkan.

## Masalah per halaman (pilihan teratas)

- Dashboard: progress bar inline-style; kanvas chart tanpa wrapper tinggi; chart hilang diam-diam bila CDN gagal.
- POS (1046 baris, 1 IIFE ±574 baris): `<style>` harusnya di corporate.css; favorit overflow di HP kecil;
  keranjang tanpa `table-responsive`; validasi pakai `alert()` ×10 (blokir kasir).
- Cetak struk: tombol PWA ikut tercetak di sebagian browser (kurang `d-print-none`);
  `auto_print` 600ms tanpa guard gesture.
- Service index: `alert()` ×6; report profit: preset **"7 Hari" mati** (berperilaku = Bulan ini).
- Cash flow: Chart.js dimuat **2×**; products/show: JsBarcode **2×**; guard `typeof` belum merata.
- Kasir cetak (`cash/index`): `window.print()` mencetak form+table sekaligus.
- Login: hint demo selalu tampil (bocor info di non-lokal); form tanpa `data-loading` (double-POST).
- Printer settings: form Default tanpa `data-loading`; filter `?store=` buang query lain.
- Publik (track/booking) + error pages: kelas `ti-*` tanpa font ikon → kotak tofu; booking tanpa validasi pola HP.
- TV display: banyak emoji (disengaja, pengecualian) + `role="marquee"` non-standar.
- Print/dokumen: tiap blade link ulang CDN Tabler (ditoleransi via SW); tombol emoji `🖨` sisa di
  member_card/voucher (dokumen, prioritas rendah).

## Kontrol mati (hasil pindai)

- Satu-satunya yang benar-benar mati: `consignments/index:258` (`href="#"` tanpa handler) dan
  preset "7 Hari" (`reports/profit:26-47`). Enam `href="#"` di layout adalah dropdown-toggle Bootstrap (fungsional).
- Tidak ada `TODO`-gated button; `alert()` ±70 situs (UX-hostile tapi fungsional); `console.error` hanya di catch.

## PWA

Installable ✅ (ikon 192/512+maskable, standalone, shortcuts POS+Lacak). Kurang (opsional):
`screenshots`/`categories`/`orientation`, maskable 192, meta iOS di halaman publik, retry-otomatis
saat online di offline.html, petunjuk iPhone "Share → Add to Home Screen".

## 10 quick wins (tanpa backend)

1. Perbaiki preset 7 Hari. 2. Ganti `alert()` kasir → toast/inline (mulai POS).
3. Deduplikasi Chart.js/JsBarcode + guard `typeof`. 4. Hapus `href="#"` konsinyasi.
5. Pindah inline style POS → corporate.css. 6. Login: `data-loading` + sembunyikan hint non-lokal.
7. Lengkapi dark-mode (form/dropdown/modal). 8. Disiplin `.print-area` + `d-print-none` di FAB.
9. PWA polish kecil (screenshot, meta publik, retry online). 10. Putuskan nasib bundle Vite.
