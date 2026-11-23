@extends('layouts.tabler')

@section('title', 'Pencarian Global')

@section('content')
<div class="page-header d-print-none">
  <div class="row align-items-center">
    <div class="col">
      <h2 class="page-title">
        Pencarian Global
      </h2>
      <div class="text-secondary mt-1">
        @if($q !== '')
          Hasil untuk
          <strong>
            "{{ $q }}"
          </strong>
          <span class="ms-1">
            (maks. 5 per kelompok, scope toko aktif)
          </span>
        @else
          Ketik kata kunci di kotak pencarian navbar
          (invoice, no. tiket, IMEI, nama/HP pelanggan, nama/SKU produk).
        @endif
      </div>
    </div>
  </div>
</div>

@if($q === '')
  <div class="card">
    <div class="card-body">
      <div class="empty">
        <p class="empty-title">
          Belum ada kata kunci
        </p>
        <p class="empty-subtitle text-secondary">
          Gunakan kotak pencarian di navbar atas untuk mencari penjualan, service, pelanggan, produk, dan unit.
        </p>
      </div>
    </div>
  </div>
@else
  {{-- Kelompok 1: Penjualan (by invoice_no) --}}
  <div class="card mb-3">
    <div class="card-header">
      <h3 class="card-title">
        Penjualan
      </h3>
      <div class="card-actions">
        <a
          href="{{ route('sales.index', ['q' => $q]) }}"
          class="btn btn-sm btn-ghost-primary"
        >
          Lihat Semua
        </a>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-vcenter card-table">
        <thead>
          <tr>
            <th>
              Invoice
            </th>
            <th>
              Pelanggan
            </th>
            <th class="text-end">
              Total
            </th>
            <th>
              Tanggal
            </th>
          </tr>
        </thead>
        <tbody>
          @forelse($sales as $s)
            <tr>
              <td>
                <a href="{{ route('sales.show', $s->id) }}">
                  {{ $s->invoice_no }}
                </a>
              </td>
              <td>
                {{ $s->customer->name ?? 'Umum' }}
              </td>
              <td class="text-end">
                Rp {{ number_format((float) $s->total, 0, ',', '.') }}
              </td>
              <td>
                {{ $s->created_at?->format('d/m/Y H:i') }}
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="4" class="text-secondary">
                Tidak ada penjualan dengan invoice "{{ $q }}".
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  {{-- Kelompok 2: Service (by ticket_no / imei) --}}
  <div class="card mb-3">
    <div class="card-header">
      <h3 class="card-title">
        Service
      </h3>
      <div class="card-actions">
        <a
          href="{{ route('services.index', ['search' => $q]) }}"
          class="btn btn-sm btn-ghost-primary"
        >
          Lihat Semua
        </a>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-vcenter card-table">
        <thead>
          <tr>
            <th>
              Tiket
            </th>
            <th>
              IMEI
            </th>
            <th>
              Pelanggan
            </th>
            <th>
              Status
            </th>
          </tr>
        </thead>
        <tbody>
          @forelse($services as $t)
            <tr>
              <td>
                <a href="{{ route('services.show', $t->id) }}">
                  {{ $t->ticket_no }}
                </a>
              </td>
              <td>
                {{ $t->imei ?? '-' }}
              </td>
              <td>
                {{ $t->customer->name ?? '-' }}
              </td>
              <td>
                {{ $t->status }}
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="4" class="text-secondary">
                Tidak ada tiket service "{{ $q }}".
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  {{-- Kelompok 3: Pelanggan (by nama / HP) --}}
  <div class="card mb-3">
    <div class="card-header">
      <h3 class="card-title">
        Pelanggan
      </h3>
      <div class="card-actions">
        <a
          href="{{ route('customers.index', ['q' => $q]) }}"
          class="btn btn-sm btn-ghost-primary"
        >
          Lihat Semua
        </a>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-vcenter card-table">
        <thead>
          <tr>
            <th>
              Nama
            </th>
            <th>
              HP
            </th>
            <th>
              Member
            </th>
          </tr>
        </thead>
        <tbody>
          @forelse($customers as $c)
            <tr>
              <td>
                <a href="{{ route('customers.show', $c->id) }}">
                  {{ $c->name }}
                </a>
              </td>
              <td>
                {{ $c->phone ?? '-' }}
              </td>
              <td>
                {{ $c->member_code ?? '-' }}
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="3" class="text-secondary">
                Tidak ada pelanggan "{{ $q }}".
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  {{-- Kelompok 4: Produk (by nama / SKU, master global) --}}
  <div class="card mb-3">
    <div class="card-header">
      <h3 class="card-title">
        Produk
      </h3>
      <div class="card-actions">
        <a
          href="{{ route('products.index', ['q' => $q]) }}"
          class="btn btn-sm btn-ghost-primary"
        >
          Lihat Semua
        </a>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-vcenter card-table">
        <thead>
          <tr>
            <th>
              Nama
            </th>
            <th>
              SKU
            </th>
            <th>
              Merek
            </th>
          </tr>
        </thead>
        <tbody>
          @forelse($products as $p)
            <tr>
              <td>
                <a href="{{ route('products.show', $p->id) }}">
                  {{ $p->name }}
                </a>
              </td>
              <td>
                {{ $p->sku }}
              </td>
              <td>
                {{ $p->brand->name ?? '-' }}
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="3" class="text-secondary">
                Tidak ada produk "{{ $q }}".
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  {{-- Kelompok 5: Unit (by IMEI / serial, scope toko aktif) --}}
  <div class="card mb-3">
    <div class="card-header">
      <h3 class="card-title">
        Unit / IMEI
      </h3>
      <div class="card-actions">
        <a
          href="{{ route('units.index', ['q' => $q]) }}"
          class="btn btn-sm btn-ghost-primary"
        >
          Lihat Semua
        </a>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-vcenter card-table">
        <thead>
          <tr>
            <th>
              IMEI
            </th>
            <th>
              Produk
            </th>
            <th>
              Status
            </th>
            <th>
              Toko
            </th>
          </tr>
        </thead>
        <tbody>
          @forelse($units as $u)
            <tr>
              <td>
                <a href="{{ route('units.index', ['q' => $u->imei]) }}">
                  {{ $u->imei ?? '-' }}
                </a>
              </td>
              <td>
                {{ $u->product->name ?? '-' }}
              </td>
              <td>
                {{ $u->status }}
              </td>
              <td>
                {{ $u->store->name ?? '-' }}
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="4" class="text-secondary">
                Tidak ada unit "{{ $q }}".
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
@endif
@endsection
