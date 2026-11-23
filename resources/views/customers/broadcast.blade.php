@extends('layouts.tabler')
@section('title','Broadcast Promo WA')
@section('content')
<div
    class="page-header"
>
    <div
        class="row align-items-center"
    >
        <div
            class="col"
        >
            <div
                class="page-pretitle"
            >Master • CRM</div>
            <h2
                class="page-title"
            >Broadcast Promo via WA</h2>
        </div>
        <div
            class="col-auto"
        >
            <a
                href="{{ route('customers.index') }}"
                class="btn btn-outline-secondary"
            >← Kembali</a>
        </div>
    </div>
</div>

@if(session('success'))
    <div
        class="alert alert-success"
    >{{ session('success') }}</div>
@endif
@if(session('error'))
    <div
        class="alert alert-danger"
    >{{ session('error') }}</div>
@endif
@if($errors->any())
    <div
        class="alert alert-danger"
    >
        <ul
            class="mb-0"
        >
            @foreach($errors->all() as $e)
                <li>{{ $e }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div
    class="alert alert-warning d-flex gap-2"
    role="alert"
>
    <div>
        <strong>Etika anti-spam — wajib dibaca:</strong>
        <ul
            class="mb-0 mt-1"
        >
            <li>
                                Kirim <strong>hanya ke pelanggan sendiri</strong> (data CRM toko ini), bukan nomor beli/scrape.
                            </li>
            <li>
                                Maksimal <strong>100 penerima per pengiriman</strong>; jangan kirim promo yang sama berulang-ulang.
                            </li>
            <li>
                                Template <strong>wajib memuat cara berhenti</strong> (mis. “Balas STOP…”) — pelanggan yang meminta
                                berhenti <strong>wajib</strong> dicatat manual &amp; tidak dikirimi promo lagi (opt-out manual:
                                kosongkan/hapus nomornya atau tandai di alamat pelanggan).
                            </li>
        </ul>
    </div>
</div>

<div
    class="card"
>
    <div
        class="card-header"
    >
        <h3
            class="card-title"
        >Form Broadcast</h3>
    </div>
    <div
        class="card-body"
    >
        <form
            method="POST"
            action="{{ route('customers.broadcast.send') }}"
        >
            @csrf
            <div
                class="row g-3"
            >
                <div
                    class="col-md-4"
                >
                    <label
                        class="form-label"
                        for="tier"
                    >Filter Tier</label>
                    <select
                        name="tier"
                        id="tier"
                        class="form-select"
                    >
                        <option
                            value=""
                        >Semua pelanggan ({{ $counts['semua'] ?? '?' }})</option>
                        @foreach(['Bronze', 'Silver', 'Gold'] as $t)
                            <option
                                value="{{ $t }}"
                                {{ old('tier', request('tier')) == $t ? 'selected' : '' }}
                            >
                                {{ $t }} ({{ $counts[$t] ?? '?' }})
                            </option>
                        @endforeach
                    </select>
                    <div
                        class="form-hint"
                    >Bronze &lt; Rp 1 jt • Silver Rp 1–5 jt • Gold &gt; Rp 5 jt.</div>
                </div>
                <div
                    class="col-md-4"
                >
                    <label
                        class="form-label"
                        for="service"
                    >Segmen Service</label>
                    <select
                        name="service"
                        id="service"
                        class="form-select"
                    >
                        <option
                            value=""
                        >Semua (tanpa filter service)</option>
                        <option
                            value="ever"
                            {{ old('service', request('service')) == 'ever' ? 'selected' : '' }}
                        >
                            Pernah service ({{ $counts['service_ever'] ?? '?' }})
                        </option>
                        <option
                            value="stale"
                            {{ old('service', request('service')) == 'stale' ? 'selected' : '' }}
                        >
                            Service &gt;6 bulan lalu ({{ $counts['service_stale'] ?? '?' }})
                        </option>
                    </select>
                    <div
                        class="form-hint"
                    >Pernah service = punya ≥1 tiket service. Opsi &gt;6 bulan untuk ajakan servis lagi.</div>
                </div>
                <div
                    class="col-md-4"
                >
                    <label
                        class="form-label"
                        for="template"
                    >Teks Template</label>
                    <textarea
                        name="template"
                        id="template"
                        rows="5"
                        maxlength="1000"
                        class="form-control font-monospace"
                        required
                        placeholder="Contoh: Halo {nama}, ada promo spesial dari {toko}! ..."
                    >{{ old('template', $defaultTemplate) }}</textarea>
                    <div
                        class="form-hint"
                    >
                        Placeholder: <code>{nama}</code> = nama pelanggan, <code>{toko}</code> = nama toko.
                        Disarankan selalu menyertakan kalimat opt-out (“Balas STOP…”).
                    </div>
                </div>
            </div>
            <div
                class="mt-3 d-flex gap-2"
            >
                <button
                    type="submit"
                    class="btn btn-primary"
                    onclick="return confirm('Kirim broadcast maksimal 100 pelanggan dengan template ini? Pastikan hanya ke pelanggan sendiri.')"
                >
                    Kirim Broadcast (maks 100)
                </button>
                <a
                    href="{{ route('customers.broadcast') }}"
                    class="btn btn-outline-secondary"
                >Reset</a>
            </div>
        </form>
    </div>
</div>

@if(!empty($result))
    <div
        class="card mt-3"
    >
        <div
            class="card-header"
        >
            <h3
                class="card-title"
            >
                Hasil Pengiriman — Tier {{ $result['tier'] }} • Service {{ $result['service'] ?? 'Semua' }}
            </h3>
        </div>
        <div
            class="card-body"
        >
            <div
                class="row g-2 mb-3"
            >
                <div
                    class="col-auto"
                >
                    <span
                        class="badge bg-secondary-lt"
                    >Target: {{ $result['target'] }}</span>
                </div>
                <div
                    class="col-auto"
                >
                    <span
                        class="badge bg-success-lt"
                    >Terkirim: {{ $result['terkirim'] }}</span>
                </div>
                <div
                    class="col-auto"
                >
                    <span
                        class="badge bg-danger-lt"
                    >Gagal: {{ $result['gagal'] }}</span>
                </div>
                <div
                    class="col-auto"
                >
                    <span
                        class="badge bg-warning-lt"
                    >Dilewati: {{ $result['dilewati'] }}</span>
                </div>
            </div>
            <div
                class="table-responsive"
            >
                <table
                    class="table table-vcenter card-table"
                >
                    <thead>
                        <tr>
                            <th>Nama</th>
                            <th>HP</th>
                            <th>Status</th>
                            <th>Keterangan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($result['details'] as $d)
                            <tr>
                                <td>{{ $d['nama'] }}</td>
                                <td>{{ $d['phone'] }}</td>
                                <td>
                                    <span
                                        class="badge bg-{{ $d['status'] == 'terkirim' ? 'success' : ($d['status'] == 'gagal' ? 'danger' : 'warning') }}-lt"
                                    >
                                        {{ $d['status'] }}
                                    </span>
                                </td>
                                <td
                                    class="text-secondary"
                                >{{ $d['ket'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td
                                    colspan="4"
                                    class="text-secondary"
                                >Tidak ada target pada filter ini.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif
@endsection
