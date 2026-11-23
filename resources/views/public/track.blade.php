<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Lacak Service — ServiceHP</title>
  <link href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css" rel="stylesheet" />
</head>
<body class="d-flex flex-column">
  <div class="page page-center">
    <div class="container container-tight py-4">
      <div class="text-center mb-4">
        <h1><i class="ti ti-tool me-1"></i> Lacak Service</h1>
        <p class="text-secondary">Masukkan nomor tiket (cth: SVC-...), IMEI, atau no. HP</p>
      </div>
      <div class="card">
        <div class="card-body">
          <form method="POST" action="{{ route('track.result') }}">
            @csrf
            <div class="input-group mb-2">
              <input
                name="ticket_no"
                class="form-control form-control-lg"
                value="{{ request('ticket_no') ?? ($ticket->ticket_no ?? '') }}"
                required
                maxlength="50"
                placeholder="SVC-... / IMEI / 0812..."
                autocomplete="off"
              />
              <button class="btn btn-primary">Lacak</button>
            </div>
            <div class="form-hint">Bisa pakai nomor tiket, IMEI perangkat, atau nomor HP yang didaftarkan saat service.</div>
          </form>

          @if (isset($ticket))
            @if ($ticket)
              <div
                class="alert alert-success mt-3"
              >
                <b>{{ $ticket->ticket_no }}</b> — {{ $ticket->product_brand }} {{ $ticket->product_type }}<br />
                Status: <b>{{ $ticket->status }}</b>
                <span class="text-secondary">• Masuk: {{ \Carbon\Carbon::parse($ticket->created_at)->format('d/m/Y') }}</span>
                @if (isset($queueAhead) && $queueAhead !== null)
                  <br />
                  <span>
                    @if ($queueAhead > 0)
                       <b>{{ $queueAhead }} tiket di depan Anda</b>
                      <span class="text-secondary">— mohon menunggu, teknisi mengerjakan sesuai urutan masuk.</span>
                    @else
                      <i class="ti ti-check me-1"></i> <b>Tidak ada antrean di depan Anda</b>
                      <span class="text-secondary">— tiket Anda prioritas berikutnya.</span>
                    @endif
                  </span>
                @endif
              </div>
              @if (isset($logs) && $logs->count())
                <ul class="timeline">
                  @foreach ($logs as $l)
                    <li>
                      <b>{{ $l->to_status }}</b>
                      <span class="text-secondary">{{ \Carbon\Carbon::parse($l->created_at)->format('d/m/Y H:i') }}</span>
                    </li>
                  @endforeach
                </ul>
                <div class="form-hint">Detail riwayat di atas hanya tahapan status, tanpa catatan internal teknisi.</div>
              @endif
            @elseif(isset($matches) && $matches->count())
              <div class="alert alert-info mt-3">
                Ditemukan {{ $matches->count() }} tiket yang cocok. Pilih nomor tiket untuk melihat statusnya.
              </div>
              <div class="list-group">
                @foreach ($matches as $m)
                  <form
                    method="POST"
                    action="{{ route('track.result') }}"
                    class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                  >
                    @csrf
                    <input
                      type="hidden"
                      name="ticket_no"
                      value="{{ $m->ticket_no }}"
                    />
                    <span>
                      <b class="font-monospace">{{ $m->ticket_no }}</b>
                      <span class="text-secondary">— {{ $m->product_brand }} {{ $m->product_type }}</span><br />
                      <span class="badge bg-secondary-lt">Status: {{ $m->status }}</span>
                      <span class="text-secondary small">• {{ \Carbon\Carbon::parse($m->created_at)->format('d/m/Y') }}</span>
                      @if (isset($m->queue_ahead) && $m->queue_ahead !== null)
                        <br />
                        <span class="small">
                          @if ($m->queue_ahead > 0)
                             {{ $m->queue_ahead }} tiket di depan
                          @else
                            <i class="ti ti-check me-1"></i> tanpa antrean
                          @endif
                        </span>
                      @endif
                    </span>
                    <button class="btn btn-sm btn-outline-primary">Lihat</button>
                  </form>
                @endforeach
              </div>
            @else
              <div class="alert alert-danger mt-3">Tiket tidak ditemukan.</div>
            @endif
          @endif

          <div class="text-center mt-3">
            <a href="{{ route('booking.form') }}">Booking service online →</a>
          </div>
        </div>
      </div>
      <p class="text-center text-secondary small mt-3">Demi privasi, halaman ini hanya menampilkan nomor tiket, perangkat, status, dan tanggal.</p>
    </div>
  </div>
</body>
</html>
