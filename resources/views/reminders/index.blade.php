@extends('layouts.tabler')
@section('title','Pusat Pengingat')
@section('content')
<div class="page-header d-print-none no-print">
  <div class="row align-items-center">
    <div class="col">
      <div class="page-pretitle">Owner/Admin • Read-only agregasi • Tanpa pengiriman massal</div>
      <h2 class="page-title"><i class="ti ti-bell me-1"></i> Pusat Pengingat ({{ $totalItems }})</h2>
      <div class="text-secondary">
        Agregat semua item pengingat dalam 1 daftar prioritas.
        Halaman ini hanya membaca data — pengiriman WA / tindak lanjut
        dilakukan manual dari halaman terkait tiap baris.
      </div>
    </div>
<div class="col-auto ms-auto d-flex flex-wrap gap-2 no-print">
      <button
        type="button"
        class="btn btn-outline-secondary"
        id="btn-toggle-ignored"
      >
        Tampilkan yang diabaikan (<span id="ignored-count">0</span>)
      </button>
      <button
        type="button"
        class="btn btn-outline-primary"
        onclick="window.print()"
      >
        <i class="ti ti-printer me-1"></i> Cetak daftar
      </button>
      <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary">← Dashboard</a>
    </div>
  </div>
</div>

<div class="alert alert-info mt-3">
  <div>
    Tidak ada tombol "Kirim Semua WA" di halaman ini (risiko spam massal).
    Buka halaman terkait tiap seksi lalu gunakan tombol ingatkan yang sudah ada di sana.
  </div>
</div>

@if(empty($sections))
  <div class="card mt-3">
    <div class="card-body text-center text-secondary py-5">
      <div class="h1"><i class="ti ti-check me-1"></i></div>
      <div class="h3">Semua beres — tidak ada pengingat hari ini.</div>
      <div>Data referensi: besok {{ $tomorrow }}.</div>
    </div>
  </div>
@else
  <div id="reminder-list">
  @foreach($sections as $s)
    <div
      class="card mt-3 reminder-section"
      data-section="{{ $s['tipe'] ?? $s['judul'] }}"
    >
      <div class="card-header">
        <h3 class="card-title">
          @if(($s['prioritas'] ?? '') === 'Mendesak')
            <span class="badge bg-red-lt me-1">Mendesak</span>
          @elseif(($s['prioritas'] ?? '') === 'Besok')
            <span class="badge bg-orange-lt me-1">Besok</span>
          @elseif(($s['prioritas'] ?? '') === 'Minggu ini')
            <span class="badge bg-yellow-lt me-1">Minggu ini</span>
          @else
            <span class="badge bg-blue-lt me-1">Rutin</span>
          @endif
          {{ $s['judul'] }}
        </h3>
      </div>
      <div class="table-responsive">
        <table class="table table-sm card-table mb-0">
          <thead>
            <tr>
              <th>Item</th>
              <th>Detail</th>
              <th>Umur</th>
              <th class="no-print">Aksi</th>
            </tr>
          </thead>
          <tbody>
            @foreach(($s['items'] ?? collect()) as $it)
              @php
                $rowHash = md5(
                    ($s['tipe'] ?? $s['judul'] ?? '')
                    . '|' . ($it['judul'] ?? '')
                    . '|' . ($it['detail'] ?? '')
                );
              @endphp
              <tr
                class="reminder-row"
                data-hash="{{ $rowHash }}"
              >
                <td>{{ $it['judul'] ?? '-' }}</td>
                <td class="text-secondary">{{ $it['detail'] ?? '-' }}</td>
                <td>
                  <span class="badge bg-secondary-lt">{{ $it['umur'] ?? '-' }}</span>
                </td>
                <td class="no-print">
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary btn-ignore-row"
                    data-hash="{{ $rowHash }}"
                    title="Sembunyikan baris ini (tersimpan di browser)"
                  >
                    Abaikan
                  </button>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <div class="card-footer d-flex align-items-center flex-wrap gap-2 no-print">
        @if((int) ($s['total'] ?? 0) > count($s['items'] ?? []))
          <span class="text-secondary small">
            Menampilkan {{ count($s['items'] ?? []) }} dari {{ $s['total'] }}.
          </span>
        @endif
        <a
          href="{{ $s['aksi_url'] }}"
          class="btn btn-sm btn-primary ms-auto"
        >
          {{ $s['aksi_label'] }} →
        </a>
      </div>
    </div>
  @endforeach
  </div>
@endif

<style>
  @media print {
    body * {
      visibility: hidden;
    }
    #reminder-list,
    #reminder-list * {
      visibility: visible;
    }
    #reminder-list {
      position: absolute;
      left: 0;
      top: 0;
      width: 100%;
    }
    .no-print,
    .card-footer,
    .btn-ignore-row {
      display: none !important;
    }
    .card {
      break-inside: avoid;
      box-shadow: none !important;
      border: 1px solid #ddd !important;
    }
  }
</style>

<script>
(function () {
  var KEY = 'reminders_ignored_v1';
  var showIgnored = false;

  function loadIgnored() {
    try {
      var raw = localStorage.getItem(KEY);
      var arr = raw ? JSON.parse(raw) : [];
      return Array.isArray(arr) ? arr : [];
    } catch (e) {
      return [];
    }
  }

  function saveIgnored(arr) {
    try {
      localStorage.setItem(KEY, JSON.stringify(arr));
    } catch (e) {
    }
  }

  function applyIgnored() {
    var ignored = loadIgnored();
    var hiddenCount = 0;

    document
      .querySelectorAll('.reminder-row[data-hash]')
      .forEach(function (row) {
        var hash = row.getAttribute('data-hash');
        var isIgnored = ignored.indexOf(hash) !== -1;
        if (isIgnored) {
          hiddenCount += 1;
        }
        row.style.display = (isIgnored && !showIgnored)
          ? 'none'
          : '';
      });

    document
      .querySelectorAll('.reminder-section')
      .forEach(function (card) {
        var visible = card.querySelectorAll(
          '.reminder-row[data-hash]:not([style*="none"])'
        ).length;
        var total = card.querySelectorAll(
          '.reminder-row[data-hash]'
        ).length;
        card.style.display = (total > 0 && visible === 0 && !showIgnored)
          ? 'none'
          : '';
      });

    var countEl = document.getElementById('ignored-count');
    if (countEl) {
      countEl.textContent = hiddenCount;
    }

    var toggleBtn = document.getElementById('btn-toggle-ignored');
    if (toggleBtn) {
      toggleBtn.textContent = '';
      var label = document.createElement('span');
      label.textContent = showIgnored
        ? 'Sembunyikan lagi ('
        : 'Tampilkan yang diabaikan (';
      var num = document.createElement('span');
      num.id = 'ignored-count';
      num.textContent = hiddenCount;
      var suffix = document.createTextNode(')');
      toggleBtn.appendChild(label);
      toggleBtn.appendChild(num);
      toggleBtn.appendChild(suffix);
    }
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest
      ? e.target.closest('.btn-ignore-row')
      : null;
    if (!btn) {
      return;
    }
    var hash = btn.getAttribute('data-hash');
    if (!hash) {
      return;
    }
    var ignored = loadIgnored();
    if (ignored.indexOf(hash) === -1) {
      ignored.push(hash);
      saveIgnored(ignored);
    }
    applyIgnored();
  });

  var toggle = document.getElementById('btn-toggle-ignored');
  if (toggle) {
    toggle.addEventListener('click', function () {
      if (showIgnored) {
        showIgnored = false;
        applyIgnored();
        return;
      }
      var ignored = loadIgnored();
      if (ignored.length === 0) {
        alert('Belum ada baris yang diabaikan.');
        return;
      }
      if (confirm('Tampilkan kembali semua baris yang diabaikan? (reset daftar abaikan di browser ini)')) {
        try {
          localStorage.removeItem(KEY);
        } catch (e) {
        }
        showIgnored = false;
        applyIgnored();
      } else {
        showIgnored = true;
        applyIgnored();
      }
    });
  }

  applyIgnored();
})();
</script>
@endsection
