@extends('layouts.tabler')
@section('title','Laba Rugi')
@section('content')
<div class="page-header"><div class="row align-items-center"><div class="col">
  <h2 class="page-title">Laporan Laba Rugi</h2><div class="text-secondary">Periode {{ $from }} s/d {{ $to }}</div>
</div><div class="col-auto no-print"><button class="btn btn-outline-secondary" onclick="window.print()">Cetak</button>
<a href="{{ route('reports.profit',['store_id'=>$storeId,'from'=>$from,'to'=>$to,'export'=>'csv']) }}" class="btn btn-outline-primary">Export CSV</a></div></div></div>

<div class="card mb-3 no-print">
    <div class="card-body">
        <form
            method="GET"
            id="profitFilterForm"
            class="row g-2"
        >
  <div class="col-md-4"><label class="form-label">Toko</label><select name="store_id" class="form-select">@foreach($stores as $s)<option value="{{ $s->id }}" {{ (int)$storeId===$s->id?'selected':'' }}>{{ $s->name }}</option>@endforeach</select></div>
  <div class="col-md-3"><label class="form-label">Dari bulan</label><input type="month" name="from" value="{{ $from }}" class="form-control"></div>
  <div class="col-md-3"><label class="form-label">Sampai bulan</label><input type="month" name="to" value="{{ $to }}" class="form-control"></div>
  <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">Tampilkan</button></div>
            <div class="col-md-12">
                <div
                    class="d-flex flex-wrap gap-1"
                    role="group"
                    aria-label="Preset tanggal cepat"
                >
                    <button
                        type="button"
                        class="btn btn-sm btn-outline-primary"
                        data-preset="today"
                    >
                        Hari ini
                    </button>
                    <button
                        type="button"
                        class="btn btn-sm btn-outline-primary"
                        data-preset="month"
                    >
                        Bulan ini
                    </button>
                </div>
            </div>
</form></div></div>

<div class="card mb-3 border-primary">
  <div class="card-header">
    <h3 class="card-title">
      Ringkasan Otomatis
    </h3>
    <span class="text-secondary ms-2">
      periode {{ $from }} s/d {{ $to }}
    </span>
  </div>
  <div class="card-body">
    <p class="mb-0">
      {{ $ringkasan ?? 'Ringkasan otomatis belum tersedia untuk periode ini.' }}
    </p>
  </div>
</div>

<div class="row row-cards mb-3">
  <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="text-secondary">Omzet Penjualan</div><div class="h3">Rp {{ number_format($omzetPenjualan,0,',','.') }}</div></div></div></div>
  <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="text-secondary">Pendapatan Service</div><div class="h3">Rp {{ number_format($pendapatanService,0,',','.') }}</div></div></div></div>
  <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="text-secondary">Laba Kotor</div><div class="h3 text-blue">Rp {{ number_format($labaKotor,0,',','.') }}</div></div></div></div>
  <div class="col-sm-6 col-lg-3"><div class="card"><div class="card-body"><div class="text-secondary">LABA BERSIH</div><div class="h3 text-{{ $labaBersih>=0?'success':'danger' }}">Rp {{ number_format($labaBersih,0,',','.') }}</div></div></div></div>
</div>

<div class="row row-cards mb-3">
  <div class="col-sm-6 col-lg-4">
    <div class="card">
      <div class="card-body">
        <div class="text-secondary">
          Total Diskon Periode
        </div>
        <div class="h3 text-warning">
          Rp {{ number_format($totalDiskon ?? 0, 0, ',', '.') }}
        </div>
        <div class="text-secondary">
          Item Rp {{ number_format($diskonItem ?? 0, 0, ',', '.') }}
          + Header/manual+promo Rp {{ number_format($diskonHeader ?? 0, 0, ',', '.') }}
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header">
    <h3 class="card-title">vs Bulan Lalu ({{ $momCurMonth ?? $to }} vs {{ $momPrevMonth ?? '-' }})</h3>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-4">
        <div class="text-secondary">Omzet Penjualan</div>
        <div class="h3">
          Rp {{ number_format($momCurOmzet ?? 0, 0, ',', '.') }}
        </div>
        <div class="text-secondary">
          Bulan lalu Rp {{ number_format($momPrevOmzet ?? 0, 0, ',', '.') }}
        </div>
        <div class="text-secondary">
          Tahun lalu ({{ $yoyMonth ?? '-' }})
          Rp {{ number_format($yoyOmzet ?? 0, 0, ',', '.') }}
        </div>
        <div class="mt-1">
          @php $v = $momOmzetPct ?? 0; @endphp
          <span class="badge bg-{{ $v > 0 ? 'success' : ($v < 0 ? 'danger' : 'secondary') }}">
            {{ $v > 0 ? '▲' : ($v < 0 ? '▼' : '■') }}
            {{ number_format(abs($v), 1, ',', '.') }}%
            {{ $v > 0 ? 'naik' : ($v < 0 ? 'turun' : 'sama') }}
          </span>
          @php $y = $yoyOmzetPct ?? 0; @endphp
          <span class="badge bg-{{ $y > 0 ? 'info' : ($y < 0 ? 'warning' : 'secondary') }}">
            YoY
            {{ $y > 0 ? '▲' : ($y < 0 ? '▼' : '■') }}
            {{ number_format(abs($y), 1, ',', '.') }}%
            {{ $y > 0 ? 'naik' : ($y < 0 ? 'turun' : 'sama') }}
          </span>
        </div>
      </div>
      <div class="col-md-4">
        <div class="text-secondary">Pendapatan Service</div>
        <div class="h3">
          Rp {{ number_format($momCurService ?? 0, 0, ',', '.') }}
        </div>
        <div class="text-secondary">
          Bulan lalu Rp {{ number_format($momPrevService ?? 0, 0, ',', '.') }}
        </div>
        <div class="mt-1">
          @php $v = $momServicePct ?? 0; @endphp
          <span class="badge bg-{{ $v > 0 ? 'success' : ($v < 0 ? 'danger' : 'secondary') }}">
            {{ $v > 0 ? '▲' : ($v < 0 ? '▼' : '■') }}
            {{ number_format(abs($v), 1, ',', '.') }}%
            {{ $v > 0 ? 'naik' : ($v < 0 ? 'turun' : 'sama') }}
          </span>
        </div>
      </div>
      <div class="col-md-4">
        <div class="text-secondary">Profit Bersih</div>
        <div class="h3 text-{{ ($momCurLaba ?? 0) >= 0 ? 'success' : 'danger' }}">
          Rp {{ number_format($momCurLaba ?? 0, 0, ',', '.') }}
        </div>
        <div class="text-secondary">
          Bulan lalu Rp {{ number_format($momPrevLaba ?? 0, 0, ',', '.') }}
        </div>
        <div class="text-secondary">
          Tahun lalu ({{ $yoyMonth ?? '-' }})
          Rp {{ number_format($yoyLaba ?? 0, 0, ',', '.') }}
        </div>
        <div class="mt-1">
          @php $v = $momLabaPct ?? 0; @endphp
          <span class="badge bg-{{ $v > 0 ? 'success' : ($v < 0 ? 'danger' : 'secondary') }}">
            {{ $v > 0 ? '▲' : ($v < 0 ? '▼' : '■') }}
            {{ number_format(abs($v), 1, ',', '.') }}%
            {{ $v > 0 ? 'naik' : ($v < 0 ? 'turun' : 'sama') }}
          </span>
          @php $y = $yoyLabaPct ?? 0; @endphp
          <span class="badge bg-{{ $y > 0 ? 'info' : ($y < 0 ? 'warning' : 'secondary') }}">
            YoY
            {{ $y > 0 ? '▲' : ($y < 0 ? '▼' : '■') }}
            {{ number_format(abs($y), 1, ',', '.') }}%
            {{ $y > 0 ? 'naik' : ($y < 0 ? 'turun' : 'sama') }}
          </span>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header">
    <h3 class="card-title">Modal vs Hasil (ROI Persediaan)</h3>
  </div>
<div class="card-body"><div class="row g-3">
  <div class="col-md-4"><div class="text-secondary">Modal tertanam persediaan</div>
    <div class="h3">Rp {{ number_format($modalTertanam ?? 0,0,',','.') }}</div>
    <div class="text-secondary">Stok Rp {{ number_format($modalStok ?? 0,0,',','.') }} + Unit tersedia Rp {{ number_format($modalUnit ?? 0,0,',','.') }}</div></div>
  <div class="col-md-4"><div class="text-secondary">Profit bersih periode ini</div>
    <div class="h3 text-{{ $labaBersih>=0?'success':'danger' }}">Rp {{ number_format($labaBersih,0,',','.') }}</div>
    <div class="text-secondary">ROI {{ number_format($roiPersen ?? 0,1,',','.') }}%</div></div>
  <div class="col-md-4"><div class="text-secondary">Rasio hasil per modal</div>
    <div class="h3 text-{{ ($roiPerSeribu ?? 0)>=0?'success':'danger' }}">Rp {{ number_format($roiPerSeribu ?? 0,0,',','.') }}</div>
    <div class="text-secondary">profit per Rp 1.000 modal tertanam</div></div>
</div></div></div>

<div class="card mb-3">
  <div class="card-header">
    <h3 class="card-title">Neraca Sederhana (posisi kini)</h3>
    <span class="text-secondary ms-2">per {{ $neracaTanggal ?? now()->toDateString() }}</span>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-4">
        <div class="text-secondary">Kas total</div>
        <div class="h3">
          Rp {{ number_format($neracaKas ?? 0, 0, ',', '.') }}
        </div>
        <div class="text-secondary">seluruh akun kas toko aktif</div>
      </div>
      <div class="col-md-4">
        <div class="text-secondary">Modal persediaan (stok + unit)</div>
        <div class="h3">
          Rp {{ number_format($modalTertanam ?? 0, 0, ',', '.') }}
        </div>
        <div class="text-secondary">
          Stok Rp {{ number_format($modalStok ?? 0, 0, ',', '.') }}
          + Unit Rp {{ number_format($modalUnit ?? 0, 0, ',', '.') }}
        </div>
      </div>
      <div class="col-md-4">
        <div class="text-secondary">Piutang outstanding</div>
        <div class="h3">
          Rp {{ number_format($piutangOutstanding ?? 0, 0, ',', '.') }}
        </div>
        <div class="text-secondary">sisa piutang toko ini</div>
      </div>
    </div>
    <hr>
    <div class="row g-3">
      <div class="col-md-4">
        <div class="text-secondary">Total ASET</div>
        <div class="h3 text-blue">
          Rp {{ number_format($neracaAset ?? 0, 0, ',', '.') }}
        </div>
        <div class="text-secondary">kas + persediaan + piutang</div>
      </div>
      <div class="col-md-4">
        <div class="text-secondary">Total KEWAJIBAN</div>
        <div class="h3">
          Rp {{ number_format($neracaKewajiban ?? 0, 0, ',', '.') }}
        </div>
        <div class="text-secondary">hutang outstanding toko ini</div>
      </div>
      <div class="col-md-4">
        <div class="text-secondary">Selisih / modal pemilik (plug)</div>
        <div class="h3 text-{{ ($neracaModalPlug ?? 0) >= 0 ? 'success' : 'danger' }}">
          Rp {{ number_format($neracaModalPlug ?? 0, 0, ',', '.') }}
        </div>
        <div class="text-secondary">aset − kewajiban; bukan double-entry</div>
      </div>
    </div>
    <div class="text-secondary mt-2">
      Persamaan: Aset (Rp {{ number_format($neracaAset ?? 0, 0, ',', '.') }})
      = Kewajiban (Rp {{ number_format($neracaKewajiban ?? 0, 0, ',', '.') }})
      + Selisih/Modal (Rp {{ number_format($neracaModalPlug ?? 0, 0, ',', '.') }}).
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header">
    <h3 class="card-title">Estimasi PPh Final UMKM (0,5% &times; omzet)</h3>
    <span class="text-secondary ms-2">tarif {{ number_format(($pphTarif ?? 0.005) * 100, 1, ',', '.') }}%</span>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-4">
        <div class="text-secondary">Omzet periode ini ({{ $from }} s/d {{ $to }})</div>
        <div class="h3">
          Rp {{ number_format($omzetPeriode ?? 0, 0, ',', '.') }}
        </div>
        <div class="text-secondary">
          penjualan Rp {{ number_format($omzetPenjualan ?? 0, 0, ',', '.') }}
          + service Rp {{ number_format($pendapatanService ?? 0, 0, ',', '.') }}
        </div>
      </div>
      <div class="col-md-4">
        <div class="text-secondary">Estimasi PPh Final periode ini</div>
        <div class="h3 text-warning">
          Rp {{ number_format($pphPeriode ?? 0, 0, ',', '.') }}
        </div>
        <div class="text-secondary">0,5% &times; omzet periode</div>
      </div>
      <div class="col-md-4">
        <div class="text-secondary">Akumulasi tahun {{ $tahunBerjalan ?? '-' }} (Jan&ndash;Des, info)</div>
        <div class="h3">
          Rp {{ number_format($pphTahunBerjalan ?? 0, 0, ',', '.') }}
        </div>
        <div class="text-secondary">
          omzet Rp {{ number_format($omzetTahunBerjalan ?? 0, 0, ',', '.') }} &times; 0,5%
        </div>
      </div>
    </div>
    <div class="alert alert-warning mt-3 mb-0">
      <div class="fw-bold">Hanya estimasi kasar, bukan ketetapan pajak.</div>
      <div>
        Angka ini bukan pengganti konsultan pajak / akuntan.
        Tarif PPh Final UMKM dapat berubah mengikuti peraturan pemerintah,
        dan ketentuan subjek, batas omzet, serta masa berlakunya bisa berbeda per wajib pajak.
        Selalu cek aturan terbaru (mis. PP 55/2022 beserta perubahannya) atau tanyakan ke konsultan pajak
        sebelum menyetor / melapor.
      </div>
    </div>
  </div>
</div>

<div class="card"><div class="table-responsive"><table class="table card-table"><thead><tr><th>Komponen</th><th class="text-end">Nominal (Rp)</th></tr></thead><tbody>
<tr><td>Omzet penjualan (sales total)</td><td class="text-end">{{ number_format($omzetPenjualan,0,',','.') }}</td></tr>
<tr><td>Profit penjualan (via sale_items, bruto)</td><td class="text-end">{{ number_format($profitPenjualan,0,',','.') }}</td></tr>
<tr><td>Retur / refund periode ini (nominal)</td><td class="text-end text-danger">({{ number_format($returAmount,0,',','.') }})</td></tr>
<tr><td>Koreksi profit retur (pengurang)</td><td class="text-end text-danger">({{ number_format($returProfit,0,',','.') }})</td></tr>
<tr><td><b>Profit penjualan BERSIH (setelah retur)</b></td><td class="text-end"><b>{{ number_format($profitPenjualanNet,0,',','.') }}</b></td></tr>
<tr>
  <td>
    <b>Total diskon periode (item + header/manual+promo)</b>
  </td>
  <td class="text-end text-warning">
    <b>{{ number_format($totalDiskon ?? 0, 0, ',', '.') }}</b>
  </td>
</tr>
<tr>
  <td>Pendapatan service (total_cost tiket)</td>
  <td class="text-end">{{ number_format($pendapatanService,0,',','.') }}</td>
</tr>
<tr><td>Omzet sparepart service</td><td class="text-end">{{ number_format($sparepartOmzet,0,',','.') }}</td></tr>
<tr><td>HPP sparepart</td><td class="text-end text-danger">({{ number_format($sparepartHpp,0,',','.') }})</td></tr>
<tr><td>Keuntungan sparepart</td><td class="text-end">{{ number_format($profitSparepart,0,',','.') }}</td></tr>
<tr><td>Pembelian / HPP barang</td><td class="text-end text-danger">({{ number_format($pembelian,0,',','.') }})</td></tr>
<tr><td>Operasional (expenses)</td><td class="text-end text-danger">({{ number_format($operasional,0,',','.') }})</td></tr>
<tr><td>Gaji / komisi teknisi</td><td class="text-end text-danger">({{ number_format($komisi,0,',','.') }})</td></tr>
<tr><td>Hutang outstanding (sisa)</td><td class="text-end">{{ number_format($hutangOutstanding,0,',','.') }}</td></tr>
<tr><td>Piutang outstanding (sisa)</td><td class="text-end">{{ number_format($piutangOutstanding,0,',','.') }}</td></tr>
<tr class="table-light"><td><b>Laba kotor</b> (profit jual bersih + service + sparepart)</td><td class="text-end"><b>{{ number_format($labaKotor,0,',','.') }}</b></td></tr>
<tr class="table-primary"><td><b>LABA BERSIH</b></td><td class="text-end"><b>{{ number_format($labaBersih,0,',','.') }}</b></td></tr>
</tbody></table></div></div>

@if(in_array(auth()->user()->role ?? '', ['superadmin', 'owner']))
  <div class="card mb-3">
    <div class="card-header">
      <h3 class="card-title">
        Perbandingan per Toko
      </h3>
      <span class="text-secondary ms-2">
        periode {{ $from }} s/d {{ $to }}
      </span>
    </div>
    <div class="table-responsive">
      <table class="table table-sm card-table">
        <thead>
          <tr>
            <th>
              Toko
            </th>
            <th class="text-end">
              Omzet (Rp)
            </th>
            <th class="text-end">
              Profit Bersih (Rp)
            </th>
            <th class="text-end">
              Transaksi
            </th>
          </tr>
        </thead>
        <tbody>
          @forelse($storeCompare ?? [] as $sc)
            <tr>
              <td>
                <a href="{{ route('reports.profit', ['store_id' => $sc['id'], 'from' => $from, 'to' => $to]) }}">
                  {{ $sc['name'] }}
                </a>
              </td>
              <td class="text-end">
                {{ number_format($sc['omzet'], 0, ',', '.') }}
              </td>
              <td class="text-end text-{{ $sc['laba'] >= 0 ? 'success' : 'danger' }}">
                {{ number_format($sc['laba'], 0, ',', '.') }}
              </td>
              <td class="text-end">
                {{ $sc['transaksi'] }}
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="4" class="text-center text-secondary">
                Belum ada data toko.
              </td>
            </tr>
          @endforelse
        </tbody>
        <tfoot>
          <tr class="table-light">
            <td>
              <b>
                Total
              </b>
            </td>
            <td class="text-end">
              <b>
                {{ number_format($storeCompareTotal['omzet'] ?? 0, 0, ',', '.') }}
              </b>
            </td>
            <td class="text-end">
              <b>
                {{ number_format($storeCompareTotal['laba'] ?? 0, 0, ',', '.') }}
              </b>
            </td>
            <td class="text-end">
              <b>
                {{ $storeCompareTotal['transaksi'] ?? 0 }}
              </b>
            </td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
@endif

<div class="row row-cards mt-3">
<div class="col-lg-8"><div class="card"><div class="card-header"><h3 class="card-title">Tren Omzet & Profit — 12 Minggu Terakhir</h3></div>
<div class="card-body"><canvas id="chartTren" height="120"></canvas></div></div></div>
<div class="col-lg-4"><div class="card"><div class="card-header"><h3 class="card-title">Komposisi Pendapatan</h3></div>
<div class="card-body"><canvas id="chartDonut" height="180"></canvas></div></div></div>
<div class="col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Top 10 Produk (Profit)</h3></div>
<div class="table-responsive"><table class="table table-sm card-table"><thead><tr><th>Produk</th><th class="text-end">Qty</th><th class="text-end">Profit</th></tr></thead><tbody>
@forelse($topProducts as $t)<tr><td>{{ $t['name'] }}</td><td class="text-end">{{ $t['qty'] }}</td><td class="text-end">{{ number_format($t['profit'],0,',','.') }}</td></tr>
@empty<tr><td colspan="3" class="text-center text-secondary">Belum ada data.</td></tr>@endforelse</tbody></table></div></div></div>
<div class="col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Rugi per Kategori Pengeluaran</h3></div>
<div class="card-body"><canvas id="chartExp" height="140"></canvas></div>
<div class="table-responsive"><table class="table table-sm card-table"><thead><tr><th>Kategori</th><th class="text-end">Total</th></tr></thead><tbody>
@forelse($expenseByCat as $e)<tr><td>{{ $e->category }}</td><td class="text-end text-danger">({{ number_format($e->total,0,',','.') }})</td></tr>
@empty<tr><td colspan="2" class="text-center text-secondary">Belum ada data.</td></tr>@endforelse</tbody></table></div></div></div>
</div>

<div class="row row-cards mt-3">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">
          Tren Tahunan {{ $tahunBerjalan ?? date('Y') }}
        </h3>
        <span class="text-secondary ms-2">
          omzet (penjualan + service) &amp; laba bersih per bulan, Jan&ndash;Des
        </span>
      </div>
      <div class="card-body">
        <canvas
          id="chartYear"
          height="120"
        ></canvas>
      </div>
      <div class="table-responsive">
        <table class="table table-sm card-table">
          <thead>
            <tr>
              <th>
                Bulan
              </th>
              <th class="text-end">
                Omzet (Rp)
              </th>
              <th class="text-end">
                Laba Bersih (Rp)
              </th>
            </tr>
          </thead>
          <tbody>
            @forelse($yearTrendRows ?? [] as $r)
              <tr>
                <td>
                  {{ $r['label'] }}
                </td>
                <td class="text-end">
                  {{ number_format($r['omzet'] ?? 0, 0, ',', '.') }}
                </td>
                <td class="text-end text-{{ ($r['laba'] ?? 0) >= 0 ? 'success' : 'danger' }}">
                  {{ number_format($r['laba'] ?? 0, 0, ',', '.') }}
                </td>
              </tr>
            @empty
              <tr>
                <td
                  colspan="3"
                  class="text-center text-secondary"
                >
                  Belum ada data tahun berjalan.
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="row row-cards mt-3">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Profit per Kategori Produk</h3>
        <span class="text-secondary ms-2">omzet &amp; profit bersih setelah retur</span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm card-table">
          <thead>
            <tr>
              <th>Kategori</th>
              <th class="text-end">Qty</th>
              <th class="text-end">Omzet (Rp)</th>
              <th class="text-end">Profit (Rp)</th>
              <th class="text-end">Margin</th>
            </tr>
          </thead>
          <tbody>
            @forelse($profitByCategory ?? [] as $k)
              <tr>
                <td>{{ $k['category'] }}</td>
                <td class="text-end">{{ $k['qty'] }}</td>
                <td class="text-end">{{ number_format($k['omzet'], 0, ',', '.') }}</td>
                <td class="text-end text-{{ $k['profit'] >= 0 ? 'success' : 'danger' }}">
                  {{ number_format($k['profit'], 0, ',', '.') }}
                </td>
                <td class="text-end">{{ number_format($k['margin'], 1, ',', '.') }}%</td>
              </tr>
            @empty
              <tr>
                <td colspan="5" class="text-center text-secondary">Belum ada data.</td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="row row-cards mt-3">
  <div class="col-12">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">
          Sering Dibeli Bersamaan
        </h3>
        <span class="text-secondary ms-2">
          top 10 pasangan dalam 1 invoice, 90 hari ({{ $seringStart ?? '-' }} s/d {{ $seringEnd ?? '-' }}, {{ $seringTotal ?? 0 }} transaksi)
        </span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm card-table">
          <thead>
            <tr>
              <th>
                Pasangan Produk
              </th>
              <th class="text-end">
                Frekuensi
              </th>
              <th class="text-end">
                % dari Transaksi
              </th>
            </tr>
          </thead>
          <tbody>
            @forelse($seringBersamaan ?? [] as $p)
              <tr>
                <td>
                  {{ $p['pasangan'] }}
                </td>
                <td class="text-end">
                  {{ $p['frekuensi'] }}
                </td>
                <td class="text-end">
                  {{ number_format($p['persen'], 1, ',', '.') }}%
                </td>
              </tr>
            @empty
              <tr>
                <td colspan="3" class="text-center text-secondary">
                  Belum ada pasangan produk (butuh invoice dengan ≥ 2 produk berbeda).
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="row row-cards mt-3">
<div class="col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Top Kasir Bulan Ini</h3></div>
<div class="table-responsive"><table class="table table-sm card-table"><thead><tr><th>Kasir</th><th class="text-end">Transaksi</th><th class="text-end">Omzet</th></tr></thead><tbody>
@forelse($topCashiers as $i=>$c)<tr><td>{{ $i+1 }}. {{ $c['name'] }}</td><td class="text-end">{{ $c['transaksi'] }}</td><td class="text-end">{{ number_format($c['omzet'],0,',','.') }}</td></tr>
@empty<tr><td colspan="3" class="text-center text-secondary">Belum ada data.</td></tr>@endforelse</tbody></table></div></div></div>
<div class="col-lg-6">
  <div class="card">
    <div class="card-header">
      <h3 class="card-title">Jam Ramai (30 hari)</h3>
      <span class="text-secondary ms-2">distribusi transaksi per jam</span>
    </div>
    <div class="card-body">
      <canvas id="chartHour" height="140"></canvas>
      <div class="alert alert-info mt-3 mb-0">
        {{ $staffTip ?? 'Belum ada data jam ramai.' }}
      </div>
      <div class="alert alert-secondary mt-2 mb-0">
        {{ $quietTip ?? 'Belum ada data jam sepi.' }}
        @if(!empty($quietHours))
          <div class="mt-1 small">
            3 jam tersepi:
            @foreach($quietHours as $qh)
              <span class="badge bg-secondary">
                {{ sprintf('%02d:00', $qh) }}
                ({{ $hourCounts[$qh] ?? 0 }} trx)
              </span>
            @endforeach
          </div>
        @endif
      </div>
    </div>
  </div>
</div>
</div>

@push('js')<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>try{
const rp=v=>'Rp '+Number(v||0).toLocaleString('id-ID');
new Chart(document.getElementById('chartTren'),{type:'line',data:{labels:@json($trendLabels),datasets:[
{label:'Omzet',data:@json($trendOmzet),borderColor:'#206bc4',tension:.3},
{label:'Profit',data:@json($trendProfit),borderColor:'#2fb344',tension:.3}]},
options:{plugins:{legend:{position:'bottom'},tooltip:{callbacks:{label:c=>' '+c.dataset.label+': '+rp(c.parsed.y)}}}}});
new Chart(document.getElementById('chartDonut'),{type:'doughnut',data:{labels:['Penjualan','Service','Sparepart'],datasets:[{data:[{{ (float)$donutPenjualan }},{{ (float)$donutService }},{{ (float)$donutSparepart }}],backgroundColor:['#206bc4','#2fb344','#f59f00']}]},options:{plugins:{legend:{position:'bottom'}}}});
new Chart(document.getElementById('chartExp'),{type:'bar',data:{labels:@json($expenseByCat->pluck('category')),datasets:[{label:'Pengeluaran',data:@json($expenseByCat->pluck('total')->map(fn($v)=>(float)$v)),backgroundColor:'#e74c3c'}]},options:{plugins:{legend:{display:false}},scales:{y:{ticks:{callback:v=>rp(v)}}}}});
new Chart(
  document.getElementById('chartYear'),
  {
    type: 'line',
    data: {
      labels: @json($yearTrendLabels ?? []),
      datasets: [
        {
          label: 'Omzet',
          data: @json($yearTrendOmzet ?? []),
          borderColor: '#206bc4',
          tension: .3
        },
        {
          label: 'Laba Bersih',
          data: @json($yearTrendLaba ?? []),
          borderColor: '#2fb344',
          tension: .3
        }
      ]
    },
    options: {
      plugins: {
        legend: { position: 'bottom' },
        tooltip: { callbacks: { label: c => ' ' + c.dataset.label + ': ' + rp(c.parsed.y) } }
      }
    }
  }
);
new Chart(document.getElementById('chartHour'),{
  type:'bar',
  data:{
    labels:@json($hourLabels ?? []),
    datasets:[{label:'Transaksi',data:@json($hourCounts ?? []),backgroundColor:'#206bc4'}]
  },
  options:{
    plugins:{legend:{display:false}},
    scales:{y:{beginAtZero:true,ticks:{precision:0}}}
  }
});
}catch(e){console.error(e);}</script>
<script>
(function () {
    var form = document.getElementById('profitFilterForm');
    if (!form) {
        return;
    }
    function fmtMonth(d) {
        var m = String(d.getMonth() + 1).padStart(2, '0');
        return d.getFullYear() + '-' + m;
    }
    function applyMonth(val) {
        var fromEl = form.querySelector('input[name="from"]');
        var toEl = form.querySelector('input[name="to"]');
        if (fromEl) {
            fromEl.value = val;
        }
        if (toEl) {
            toEl.value = val;
        }
        form.submit();
    }
    form.querySelectorAll('[data-preset]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            applyMonth(fmtMonth(new Date()));
        });
    });
})();
</script>@endpush
@endsection
