/* ============================================================
 * PrintHub — Universal printing untuk ServiceHP (Tabler UI)
 * - Browser print → SEMUA printer (USB, jaringan, inkjet/laser,
 *   thermal + driver, PDF) via window.print + @page
 * - Bluetooth thermal (ESC/POS) via Web Bluetooth (Chrome/Edge)
 * - USB thermal (ESC/POS) via WebUSB (Chrome desktop)
 * - Unduh PNG struk (untuk RawBT / arsip / WA) via html2canvas
 * - Bagikan file (Web Share API, HP Android/iOS)
 * ============================================================ */
(function () {
  'use strict';

  const chr = (...c) => String.fromCharCode(...c);

  /** Builder ESC/POS — cermin dari App\Services\EscPosService (PHP). */
  class EscPos {
    constructor(cols = 32) { this.cols = cols; this.parts = []; this.textEnc = new TextEncoder(); }
    raw(...bytes) { this.parts.push(new Uint8Array(bytes)); return this; }
    init() { return this.raw(0x1B, 0x40); }
    align(p = 'left') { return this.raw(0x1B, 0x61, { left: 0, center: 1, right: 2 }[p] ?? 0); }
    bold(on = true) { return this.raw(0x1B, 0x45, on ? 1 : 0); }
    doubleSize(on = true) { return this.raw(0x1D, 0x21, on ? 0x11 : 0x00); }
    line(ch = '-') { return this.text(String(ch).repeat(this.cols)); }
    static ascii(s) {
      return String(s ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        .replace(/[^\x20-\x7E\n]/g, '?');
    }
    text(s) {
      const b = this.textEnc.encode(EscPos.ascii(s) + '\n');
      this.parts.push(b); return this;
    }
    wrap(s) {
      const words = EscPos.ascii(s).split(' ');
      let line = '';
      for (const w of words) {
        if ((line + ' ' + w).trim().length > this.cols) { this.text(line.trim()); line = w; }
        else line += ' ' + w;
      }
      if (line.trim()) this.text(line.trim());
      return this;
    }
    row(left, right) {
      left = EscPos.ascii(left); right = EscPos.ascii(right);
      const sp = this.cols - left.length - right.length;
      this.text(sp >= 1 ? left + ' '.repeat(sp) + right : left + '\n' + right.padStart(this.cols, ' '));
      return this;
    }
    feed(n = 3) { return this.raw(0x1B, 0x64, Math.min(255, n)); }
    cut() { this.feed(4); return this.raw(0x1D, 0x56, 0x01); }
    /** Buka laci kas: ESC p m t1 t2 — standar 1B 70 00 19 FA (pin 0, t1=25, t2=250). */
    drawer(pin = 0, t1 = 0x19, t2 = 0xFA) {
      return this.raw(0x1B, 0x70, pin ? 1 : 0, t1 & 0xFF, t2 & 0xFF);
    }
    qr(data, size = 6) {
      data = EscPos.ascii(data).slice(0, 400);
      const db = this.textEnc.encode(data);
      this.raw(0x1D, 0x28, 0x6B, 0x04, 0x00, 0x31, 0x41, 0x32, 0x00);
      this.raw(0x1D, 0x28, 0x6B, 0x03, 0x00, 0x31, 0x43, size);
      this.raw(0x1D, 0x28, 0x6B, 0x03, 0x00, 0x31, 0x45, 0x31);
      const len = db.length + 3;
      this.raw(0x1D, 0x28, 0x6B, len % 256, Math.floor(len / 256), 0x31, 0x50, 0x30);
      this.parts.push(db);
      this.raw(0x1D, 0x28, 0x6B, 0x03, 0x00, 0x31, 0x51, 0x30);
      return this.text('');
    }
    bytes() {
      let n = 0; for (const p of this.parts) n += p.length;
      const out = new Uint8Array(n); let o = 0;
      for (const p of this.parts) { out.set(p, o); o += p.length; }
      return out;
    }
  }

  /** Bangun byte struk penjualan dari <script id="receipt-data"> JSON. */
  function saleBytes(d, cols) {
    const e = new EscPos(cols); e.init();
    e.align('center').bold().text(d.store.name || 'ServiceHP').bold(false);
    if (d.header_lines && d.header_lines.length) d.header_lines.forEach(l => e.text(l));
    else { if (d.store.address) e.text(d.store.address); if (d.store.phone) e.text(d.store.phone); }
    e.line().align('left')
      .row('No:', d.invoice).row('Tgl:', d.datetime)
      .row('Kasir:', d.cashier).row('Plg:', d.customer).line();
    for (const it of d.items) {
      e.wrap(it.name);
      e.row(`  ${it.qty} x ${fmt(it.price)}`, fmt(it.subtotal));
    }
    e.line().row('Subtotal', fmt(d.subtotal)).row('Diskon', fmt(d.discount));
    if (d.tradein > 0) e.row('Tukar tambah', '-' + fmt(d.tradein));
    if (Number(d.tax) > 0) {
      e.row('Pajak', fmt(d.tax));
    }
    e.bold().row('TOTAL Rp', fmt(d.total)).bold(false);
    e.row(`Bayar(${d.pay_method})`, fmt(d.paid));
    const change = Math.max(0, (Number(d.paid) || 0) - (Number(d.total) || 0));
    if (change > 0) e.bold().row('KEMBALIAN Rp', fmt(change)).bold(false);
    e.row(`Sisa(${d.pay_status})`, fmt(d.remaining));
    e.line().align('center');
    if (d.footer_lines && d.footer_lines.length) d.footer_lines.forEach(l => e.text(l));
    else e.text('Terima kasih atas kunjungan Anda').text('Garansi & retur maks 7 hari + struk');
    if (d.ucapan) { e.line().align('center').bold().text('*** HADIAH ***').bold(false).wrap(String(d.ucapan)); }
    // QR info pembayaran (BUKAN QRIS dinamis): payload teks invoice+total utk verifikasi kasir.
    try {
      const payQr = EscPos.ascii(d.pay_qr || ('SERVICEHP|' + (d.invoice || '') + '|' + Math.round(Number(d.total) || 0)));
      if (payQr) { e.qr(payQr).text('QR info pembayaran').text(String(d.invoice || '')); }
    } catch {}
    e.cut();
    return e.bytes();
  }

  function serviceBytes(d, cols) {
    const e = new EscPos(cols); e.init();
    e.align('center').bold().doubleSize().text('SERVICE').doubleSize(false).bold(false);
    e.text(d.store.name || 'ServiceHP');
    if (d.header_lines && d.header_lines.length) d.header_lines.forEach(l => e.text(l));
    else if (d.store.phone) e.text(d.store.phone);
    e.line().align('left')
      .row('Tiket:', d.ticket).row('Tgl:', d.datetime)
      .row('Plg:', d.customer).row('Device:', d.device);
    if (d.imei) e.row('IMEI:', d.imei);
    e.line().wrap('Keluhan: ' + d.complaint);
    if (d.condition) e.wrap('Kondisi: ' + d.condition);
    e.line().row('Estimasi', 'Rp ' + fmt(d.estimate)).row('DP', 'Rp ' + fmt(d.dp))
      .row('Status', d.status.toUpperCase())
      .line().align('center').qr(d.track_url).text(d.ticket)
      .text('Scan utk lacak status service');
    if (d.footer_lines && d.footer_lines.length) d.footer_lines.forEach(l => e.text(l));
    else e.text('Wajib bawa tanda terima saat ambil');
    e.cut();
    return e.bytes();
  }

  const fmt = (n) => new Intl.NumberFormat('id-ID').format(Number(n) || 0);

  // ---------- Pesan error Bluetooth yang ramah (Bahasa Indonesia) ----------
  function btErrorMsg(e) {
    const n = (e && e.name) || '';
    const m = (e && e.message) || String(e);
    if (n === 'NotFoundError' || /cancelled|canceled|no device|not found|tidak ada perangkat/i.test(m))
      return 'Perangkat tidak dipilih / tidak ditemukan. Pastikan printer Bluetooth sudah NYALA, tidak terhubung ke HP lain, lalu tekan Bluetooth dan pilih printer dari daftar.';
    if (n === 'NotSupportedError' || /not supported|tidak mendukung/i.test(m))
      return 'Perangkat/browser tidak mendukung Web Bluetooth. Gunakan Chrome/Edge versi terbaru di Android/PC.';
    if (n === 'SecurityError' || /https|secure/i.test(m))
      return 'Web Bluetooth butuh HTTPS atau localhost. Buka ServiceHP lewat HTTPS.';
    if (n === 'NetworkError' || /gatt|disconnect|terputus|disconnected/i.test(m))
      return 'Koneksi Bluetooth terputus (GATT). Dekatkan HP ke printer, pastikan baterai cukup, lalu ulangi. Jika masih gagal, lupakan (unpair) printer di setelan Bluetooth lalu pairing ulang.';
    return 'Gagal Bluetooth: ' + m + '. Pastikan printer NYALA, kertas terpasang, dan tidak dipakai aplikasi lain.';
  }

  // ---------- Transport terakhir (diingat per sesi browser, tanpa backend) ----------
  // Diisi otomatis setiap kirim BT/USB manual berhasil → dipakai acuan drawer-kick
  // otomatis. Disimpan di sessionStorage agar berlaku di semua tab sesi itu.
  const LAST_TP_KEY = 'shp_last_transport'; // 'bluetooth' | 'usb'
  function lastTransport() {
    try { return sessionStorage.getItem(LAST_TP_KEY) || ''; } catch { return ''; }
  }
  function rememberTransport(t) {
    if (t !== 'bluetooth' && t !== 'usb') return;
    try { sessionStorage.setItem(LAST_TP_KEY, t); } catch {}
  }

  // ---------- Transport: Bluetooth ----------
  // Tulis byte ke device yang SUDAH dipilih (tanpa requestDevice = tanpa popup pairing).
  async function btWriteBytes(device, bytes, log) {
    const server = await device.gatt.connect();
    const services = await server.getPrimaryServices();
    let writer = null;
    for (const s of services) {
      let chars = [];
      try { chars = await s.getCharacteristics(); } catch { continue; }
      for (const c of chars) {
        if (c.properties.writeWithoutResponse || c.properties.write) { writer = c; break; }
      }
      if (writer) break;
    }
    if (!writer) { try { server.disconnect(); } catch {} throw new Error('Printer ketemu, tapi tidak ada karakteristik tulis (write) yang cocok. Printer ini mungkin hanya mendukung mode SPP klasik — gunakan aplikasi RawBT, atau cetak via tombol browser/USB/LAN.'); }
    const noResp = !!writer.properties.writeWithoutResponse && !writer.properties.write;
    const MTU = noResp ? 100 : 20;
    log(`Mengirim ${bytes.length} byte…`);
    for (let i = 0; i < bytes.length; i += MTU) {
      const chunk = bytes.slice(i, i + MTU);
      let attempt = 0, sent = false, lastErr = null;
      while (attempt < 2 && !sent) { // retry otomatis 1x per chunk
        try {
          if (noResp) { await writer.writeValueWithoutResponse(chunk); await sleep(30); }
          else await writer.writeValueWithResponse(chunk);
          sent = true;
        } catch (e) {
          lastErr = e; attempt++;
          if (attempt < 2) { log(`Chunk ${i}-${i + chunk.length} gagal, mencoba ulang…`); await sleep(250); }
        }
      }
      if (!sent) throw new Error(btErrorMsg(lastErr) + ` (gagal pada byte ${i}/${bytes.length} setelah 2x percobaan)`);
      log(`Terkirim ${Math.min(i + MTU, bytes.length)}/${bytes.length} byte`);
    }
    try { server.disconnect(); } catch {}
    log('Selesai.');
  }

  async function sendBluetooth(bytes, onLog) {
    if (!navigator.bluetooth) throw new Error('Browser tidak mendukung Web Bluetooth. Gunakan Chrome/Edge (Android/PC).');
    const log = onLog || (() => {});
    let device;
    try {
      log('Mencari printer Bluetooth…');
      device = await navigator.bluetooth.requestDevice({        acceptAllDevices: true,
        optionalServices: [0x18f0, 0xffe0, 0xffe5, 0xfff0, '0000ff00-0000-1000-8000-00805f9b34fb'].map(u =>
          typeof u === 'number' ? BluetoothUUID.getService(u) : u),
      });
    } catch (e) { throw new Error(btErrorMsg(e)); }
    try {
      log('Menghubungkan ke ' + (device.name || 'printer') + '…');
      await btWriteBytes(device, bytes, log);
      rememberTransport('bluetooth');
    } catch (e) {
      try { if (device && device.gatt && device.gatt.connected) device.gatt.disconnect(); } catch {}
      if (/Printer ketemu|tidak mendukung Web Bluetooth|tidak dipilih/i.test(e.message)) throw e;
      throw new Error(btErrorMsg(e));
    }
  }

  // Tulis byte ke device USB yang SUDAH diizinkan (tanpa requestDevice = tanpa popup).
  async function usbWriteBytes(device, bytes, log) {
    await device.open();
    if (!device.configuration) await device.selectConfiguration(1);
    const iface = device.configuration.interfaces.find(i =>
      i.alternates.some(a => a.interfaceClass === 7)) || device.configuration.interfaces[0];
    await device.claimInterface(iface.interfaceNumber);
    const ep = iface.alternates.flatMap(a => a.endpoints).find(e => e.direction === 'out');
    if (!ep) throw new Error('Endpoint OUT tidak ditemukan.');
    log(`Mengirim ${bytes.length} byte…`);
    for (let i = 0; i < bytes.length; i += 512) {
      await device.transferOut(ep.endpointNumber, bytes.slice(i, i + 512));
    }
    await device.close();
    log('Selesai.');
  }

  // ---------- Transport: USB (WebUSB, printer class 7) ----------
  async function sendUSB(bytes, onLog) {
    if (!navigator.usb) throw new Error('Browser tidak mendukung WebUSB. Gunakan Chrome desktop.');
    const log = onLog || (() => {});
    log('Pilih printer USB…');
    const device = await navigator.usb.requestDevice({ filters: [{ classCode: 7 }, {}] });
    await usbWriteBytes(device, bytes, log);
    rememberTransport('usb');
  }

  // ---------- Browser print (SEMUA printer + PDF) ----------
  function browserPrint(areaId, paper = '80mm') {
    document.body.setAttribute('data-print-paper', paper);
    document.querySelectorAll('.print-area').forEach(el => el.classList.remove('print-active'));
    const el = document.getElementById(areaId);
    if (!el) { window.print(); return; }
    el.classList.add('print-active');
    setTimeout(() => { window.print(); setTimeout(() => el.classList.remove('print-active'), 500); }, 50);
  }

  // ---------- PNG (untuk RawBT / arsip / WA) ----------
  async function downloadPNG(areaId, filename = 'struk.png') {
    const el = document.getElementById(areaId);
    if (!el) throw new Error('Area cetak tidak ditemukan.');
    if (typeof html2canvas === 'undefined') throw new Error('html2canvas belum termuat (butuh internet CDN).');
    const canvas = await html2canvas(el, { scale: 2, backgroundColor: '#ffffff' });
    const blob = await new Promise(r => canvas.toBlob(r, 'image/png'));
    if (navigator.canShare && navigator.canShare({ files: [new File([blob], filename, { type: 'image/png' })] })) {
      await navigator.share({ files: [new File([blob], filename, { type: 'image/png' })], title: filename });
      return 'shared';
    }
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = filename; a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 5000);
    return 'downloaded';
  }

  // ---------- PNG raster → ESC/POS (cetak GAMBAR struk via BT/USB) ----------
  // Render elemen HTML (struk) jadi bitmap B/W lalu bungkus GS v 0 (raster, m=0).
  // widthPx: 384 utk 58mm, 576 utk 80mm (203 dpi). MSB dulu, little-endian.
  function imageRasterBytes(canvas, widthPx) {
    const srcW = canvas.width, srcH = canvas.height;
    if (!srcW || !srcH) throw new Error('Hasil render struk kosong (0px). Coba lagi.');
    const w = Math.max(8, Math.min(576, Math.round(widthPx) || 384));
    const h = Math.max(1, Math.round((srcH * w) / srcW));
    const cv = document.createElement('canvas');
    cv.width = w; cv.height = h;
    const ctx = cv.getContext('2d', { willReadFrequently: true });
    ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, w, h);
    ctx.drawImage(canvas, 0, 0, w, h);
    let img;
    try {
      img = ctx.getImageData(0, 0, w, h);
    } catch (e) {
      throw new Error('Gambar struk tidak bisa dibaca (canvas terkunci/CORS — biasanya karena logo/foto dari domain lain). Simpan logo di server ini (folder public/) lalu ulangi, atau gunakan tombol Bluetooth/USB teks biasa.');
    }
    const rowBytes = Math.ceil(w / 8);
    const px = img.data;
    // Bitmap B/W penuh (1 = titik hitam)
    const bits = new Uint8Array(rowBytes * h);
    for (let y = 0; y < h; y++) {
      for (let x = 0; x < w; x++) {
        const i = (y * w + x) * 4;
        const lum = 0.299 * px[i] + 0.587 * px[i + 1] + 0.114 * px[i + 2];
        if (lum < 128) bits[y * rowBytes + (x >> 3)] |= (0x80 >> (x & 7));
      }
    }
    // Bungkus per pita (band) max 200 baris agar buffer printer BT murah tidak jebol.
    const BAND = 200;
    const parts = [new Uint8Array([0x1B, 0x40])]; // INIT
    let total = 2;
    for (let y0 = 0; y0 < h; y0 += BAND) {
      const bh = Math.min(BAND, h - y0);
      const head = new Uint8Array([0x1D, 0x76, 0x30, 0x00,
        rowBytes & 0xFF, (rowBytes >> 8) & 0xFF,
        bh & 0xFF, (bh >> 8) & 0xFF]);
      const body = bits.slice(y0 * rowBytes, (y0 + bh) * rowBytes);
      parts.push(head, body);
      total += head.length + body.length;
    }
    const tail = new Uint8Array([0x1B, 0x64, 0x04, 0x1D, 0x56, 0x01]); // feed + CUT
    parts.push(tail); total += tail.length;
    const out = new Uint8Array(total);
    let o = 0;
    for (const p of parts) { out.set(p, o); o += p.length; }
    return { bytes: out, widthPx: w, heightPx: h };
  }

  async function printImage(areaId, transport = 'bluetooth', widthPx = 384, onLog) {
    const log = onLog || (() => {});
    const el = document.getElementById(areaId);
    if (!el) throw new Error('Area cetak "' + areaId + '" tidak ditemukan di halaman ini.');
    if (typeof html2canvas === 'undefined')
      throw new Error('html2canvas belum termuat (butuh internet CDN cdnjs). Sambungkan internet lalu muat ulang halaman. Alternatif: gunakan tombol Bluetooth/USB teks biasa yang tidak butuh html2canvas.');
    log('Merender struk jadi gambar…');
    let canvas;
    try {
      canvas = await html2canvas(el, { scale: 2, backgroundColor: '#ffffff', useCORS: true, logging: false });
    } catch (e) {
      throw new Error('Gagal render gambar struk (html2canvas): ' + (e && e.message ? e.message : e) + '. Periksa koneksi internet/CDN, lalu ulangi. Alternatif: gunakan tombol Bluetooth/USB teks.');
    }
    log('Mengubah ke hitam-putih…');
    await sleep(10);
    const { bytes, widthPx: w, heightPx: h } = imageRasterBytes(canvas, widthPx);
    log(`Mengirim gambar ${w}×${h}px (${bytes.length} byte)… sabar, mode gambar ±10–30 dtk.`);
    if (transport === 'usb') return sendUSB(bytes, log);
    return sendBluetooth(bytes, log);
  }

  const sleep = (ms) => new Promise(r => setTimeout(r, ms));

  // ---------- Buka laci kas (cash drawer) — ESC/POS ESC p m t1 t2 ----------
  // Standar: 1B 70 00 19 FA (pin 0, on-time t1=25×2ms=50ms, off-time t2=250×2ms=500ms).
  // Cocok untuk Xprinter / Epson TM / Star (mode ESC/POS) via port drawer RJ11.
  // Tanpa backend: byte dikirim langsung via transport BT/USB yang sudah ada.
  const DRAWER_BYTES = [0x1B, 0x70, 0x00, 0x19, 0xFA];
  function drawerBytes(pin = 0, t1 = 0x19, t2 = 0xFA) {
    return new Uint8Array([0x1B, 0x70, pin ? 1 : 0, t1 & 0xFF, t2 & 0xFF]);
  }
  async function openDrawer(transport = 'bluetooth', pin = 0, onLog) {
    const log = onLog || (() => {});
    const bytes = drawerBytes(pin);
    log('Membuka laci kas (ESC p m t1 t2 = 1B 70 ' + [...bytes].slice(2).map(b => b.toString(16).toUpperCase().padStart(2, '0')).join(' ') + ')…');
    if (transport === 'usb') { await sendUSB(bytes, log); return { ok: true, transport: 'usb' }; }
    await sendBluetooth(bytes, log); return { ok: true, transport: 'bluetooth' };
  }

  // ---------- Buka laci OTOMATIS tanpa pairing (dipakai auto_drawer) ----------
  // Syarat: transport BT/USB yang terakhir dipakai sesi ini TERSIMPAN (rememberTransport)
  // dan device-nya masih diizinkan browser (getDevices). TIDAK PERNAH memanggil
  // requestDevice → tidak ada popup pairing. Bila belum pernah connect / izin hilang /
  // browser tak mendukung getDevices → kembalikan { skipped:true } agar pemanggil
  // melewatinya diam-diam (tanpa alert, tanpa error ke kasir).
  async function openDrawerSilent(onLog) {
    const log = onLog || (() => {});
    const tp = lastTransport();
    if (!tp) return { skipped: true, reason: 'belum-pernah-connect' };
    const bytes = drawerBytes(0);
    try {
      if (tp === 'usb') {
        if (!navigator.usb || !navigator.usb.getDevices) return { skipped: true, reason: 'usb-tak-didukung' };
        const devs = await navigator.usb.getDevices();
        if (!devs.length) return { skipped: true, reason: 'usb-belum-diizinkan' };
        log('Membuka laci kas otomatis (USB, tanpa pairing)…');
        await usbWriteBytes(devs[0], bytes, log);
        return { ok: true, transport: 'usb' };
      }
      if (!navigator.bluetooth || !navigator.bluetooth.getDevices) return { skipped: true, reason: 'bt-tak-didukung' };
      const devs = await navigator.bluetooth.getDevices();
      if (!devs.length) return { skipped: true, reason: 'bt-belum-diizinkan' };
      log('Membuka laci kas otomatis (Bluetooth, tanpa pairing)…');
      await btWriteBytes(devs[0], bytes, log);
      return { ok: true, transport: 'bluetooth' };
    } catch (e) {
      log('Laci otomatis dilewati: ' + (e && e.message ? e.message : e));
      return { skipped: true, reason: 'gagal-tulis' };
    }
  }

  // ---------- Printer LAN default PER LEBAR (localStorage per kasir/HP) ----------
  const LAN_KEY = (w) => 'shp_lan_default_' + w; // w: 58 | 80
  function lanDefault(widthMm) {
    try { return localStorage.getItem(LAN_KEY(widthMm)) || ''; } catch { return ''; }
  }
  function lanSetDefault(widthMm, printerId) {
    try { localStorage.setItem(LAN_KEY(widthMm), printerId); } catch {}
  }
  /** Pilih otomatis option printer yang lebarnya cocok; kembalikan id terpilih. */
  function lanAutoPick(selectEl, widthMm) {
    if (!selectEl) return '';
    const saved = lanDefault(widthMm);
    if (saved && [...selectEl.options].some(o => o.value === saved)) {
      selectEl.value = saved; return saved;
    }
    const match = [...selectEl.options].find(o => o.dataset.width == String(widthMm));
    if (match) { selectEl.value = match.value; return match.value; }
    return selectEl.value || '';
  }

  function receiptData() {
    const el = document.getElementById('receipt-data');
    return el ? JSON.parse(el.textContent) : null;
  }

  window.PrintHub = {
    EscPos, saleBytes, serviceBytes, sendBluetooth, sendUSB, browserPrint, downloadPNG,
    printImage, imageRasterBytes, receiptData, drawerBytes, openDrawer, openDrawerSilent,
    lastTransport, rememberTransport, DRAWER_BYTES,
    lanDefault, lanSetDefault, lanAutoPick,
    supported: {
      bluetooth: 'bluetooth' in navigator,
      usb: 'usb' in navigator,
      share: 'share' in navigator,
    },
  };
})();
