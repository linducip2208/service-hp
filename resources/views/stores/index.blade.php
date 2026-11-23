@extends('layouts.tabler')

@section('title', 'Toko')

@section('content')
    <div class="page-header d-print-none">
        <div class="row align-items-center">
            <div class="col">
                <h2 class="page-title">
                    Toko / Cabang
                </h2>
                <div class="text-secondary mt-1">
                    Bagikan lokasi toko ke pelanggan via Google Maps & WhatsApp.
                </div>
            </div>
            <div class="col-auto ms-auto d-print-none">
                @if(in_array(auth()->user()->role ?? '', ['superadmin','owner']))
                <a
                    href="{{ route('stores.index', array_merge(request()->query(), ['export' => 'csv'])) }}"
                    class="btn btn-outline-success me-2"
                >
                    <i class="ti ti-download me-1"></i> Export CSV
                </a>
                <a href="{{ route('stores.create') }}" class="btn btn-primary">
                    + Toko
                </a>
                @endif
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                Daftar Toko
            </h3>
            <form
                class="ms-auto d-flex gap-2"
                method="GET"
            >
                <input
                    name="q"
                    value="{{ request('q') }}"
                    class="form-control form-control-sm"
                    placeholder="Cari nama / kode toko"
                >
                <button class="btn btn-sm btn-outline-primary">
                    Cari
                </button>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Nama / Alamat</th>
                        <th>Telepon</th>
                        <th>Pusat</th>
                        <th>Status</th>
                        <th>Users</th>
                        <th class="text-end">Bagikan Lokasi</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($stores as $s)
                        @php
                            $mapsUrl = $s->address
                                ? 'https://www.google.com/maps/search/?api=1&query=' . urlencode($s->name . ' ' . $s->address)
                                : null;
                            $shareText = $mapsUrl
                                ? 'Lokasi ' . $s->name . ': ' . $s->address . ' ' . $mapsUrl
                                : null;
                            $waUrl = $shareText
                                ? 'https://wa.me/?text=' . urlencode($shareText)
                                : null;
                        @endphp
                        <tr>
                            <td>{{ $s->code }}</td>
                            <td>
                                <div class="fw-bold">{{ $s->name }}</div>
                                @if($s->address)
                                    <div class="text-secondary small">{{ $s->address }}</div>
                                @else
                                    <div class="text-secondary small fst-italic">Alamat belum diisi</div>
                                @endif
                            </td>
                            <td>{{ $s->phone ?? '-' }}</td>
                            <td>
                                @if($s->is_center)
                                    <span class="badge bg-yellow-lt">Pusat</span>
                                @else
                                    -
                                @endif
                            </td>
                            <td>
                                <span class="badge bg-{{ $s->is_active ? 'green' : 'red' }}-lt">
                                    {{ $s->is_active ? 'Aktif' : 'Nonaktif' }}
                                </span>
                            </td>
                            <td>{{ $s->users_count }}</td>
                            <td class="text-end">
                                @if($mapsUrl)
                                    <div class="btn-group flex-wrap" role="group" aria-label="Bagikan lokasi {{ $s->name }}">
                                        <a
                                            href="{{ $mapsUrl }}"
                                            target="_blank"
                                            rel="noopener"
                                            class="btn btn-sm btn-outline-primary"
                                            title="Buka di Google Maps: {{ $s->address }}"
                                        >
                                            Maps
                                        </a>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-secondary btn-copy-maps"
                                            data-maps="{{ $mapsUrl }}"
                                            data-name="{{ $s->name }}"
                                            title="Salin link Google Maps"
                                        >
                                            Salin Link
                                        </button>
                                        <a
                                            href="{{ $waUrl }}"
                                            target="_blank"
                                            rel="noopener"
                                            class="btn btn-sm btn-outline-success"
                                            title="Bagikan alamat + link Maps via WhatsApp"
                                        >
                                            WA
                                        </a>
                                    </div>
                                @else
                                    <span class="text-secondary small">-</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('stores.show', $s) }}" class="btn btn-sm btn-outline-primary">
                                    Detail
                                </a>
                                <a href="{{ route('stores.edit', $s) }}" class="btn btn-sm btn-outline-secondary">
                                    Edit
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-secondary py-4">
                                Belum ada toko.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            {{ $stores->links() }}
        </div>
    </div>
@endsection

@push('js')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.btn-copy-maps').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var link = btn.getAttribute('data-maps') || '';
                    if (!link) return;
                    var done = function () {
                        var label = btn.innerHTML;
                        btn.innerHTML = 'Tersalin!';
                        setTimeout(function () { btn.innerHTML = label; }, 1500);
                    };
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(link).then(done).catch(function () {
                            fallbackCopy(link, done);
                        });
                    } else {
                        fallbackCopy(link, done);
                    }
                });
            });

            function fallbackCopy(text, cb) {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); } catch (e) {}
                document.body.removeChild(ta);
                if (cb) cb();
            }
        });
    </script>
@endpush
