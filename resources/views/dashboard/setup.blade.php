@extends('layouts.tabler')
@section('title','Checklist Setup Toko')
@section('content')
<div class="page-header d-print-none">
    <div class="row align-items-center">
        <div class="col">
            <h2 class="page-title">
                Checklist Setup Toko
            </h2>
            <div class="text-secondary">
                Selesaikan {{ $total }} langkah agar toko siap operasional. Sudah selesai {{ $done }} dari {{ $total }} ({{ $pct }}%).
            </div>
        </div>
        <div class="col-auto ms-auto">
            <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary">
                Kembali ke Dashboard
            </a>
        </div>
    </div>
</div>
<div class="card mt-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="subheader mb-0">
                Progres setup
            </div>
            <span class="badge bg-{{ $pct >= 100 ? 'green' : ($pct >= 50 ? 'blue' : 'yellow') }}-lt">
                {{ $done }}/{{ $total }} ({{ $pct }}%)
            </span>
        </div>
        <div class="progress" style="height:12px">
            <div
                class="progress-bar bg-{{ $pct >= 100 ? 'success' : 'primary' }}"
                role="progressbar"
                style="width: {{ $pct }}%"
                aria-valuenow="{{ $pct }}"
                aria-valuemin="0"
                aria-valuemax="100"
            >
            </div>
        </div>
        @if($pct >= 100)
            <div class="alert alert-success mt-3 mb-0">
                Semua langkah selesai. Toko siap operasional penuh.
            </div>
        @endif
    </div>
</div>
<div class="row row-cards mt-3">
    @foreach($steps as $s)
        <div class="col-md-6 col-lg-4">
            <div class="card {{ $s['done'] ? 'border-success' : 'border-warning' }}">
                <div class="card-body">
                    <div class="d-flex align-items-start gap-2">
                        <span style="font-size:1.5rem;line-height:1">
                            @if($s['done'])<i class="ti ti-circle-check text-success"></i>@else<i class="ti ti-circle text-secondary"></i>@endif
                        </span>
                        <div class="flex-fill">
                            <h3 class="card-title mb-1">
                                {{ $s['label'] }}
                            </h3>
                            <div class="text-secondary small mb-2">
                                {{ $s['desc'] }}
                            </div>
                            <span class="badge bg-{{ $s['done'] ? 'green' : 'yellow' }}-lt mb-2">
                                {{ $s['done'] ? 'Selesai' : 'Belum' }}
                            </span>
                            <div>
                                <a href="{{ $s['url'] }}" class="btn btn-sm btn-{{ $s['done'] ? 'ghost-success' : 'primary' }}">
                                    {{ $s['cta'] }} →
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>
@endsection
