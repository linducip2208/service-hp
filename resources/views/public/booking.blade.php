<!doctype html><html lang="id"><head><meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/><title>Booking Service — ServiceHP</title><link href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css" rel="stylesheet"/></head>
<body class="d-flex flex-column"><div class="page page-center"><div class="container container-tight py-4">
<div class="text-center mb-4"><h1><i class="ti ti-calendar me-1"></i> Booking Service</h1></div>
<div class="card"><div class="card-body">
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<form method="POST" action="{{ route('booking.store') }}">@csrf
<div class="mb-3"><label class="form-label">Toko</label><select name="store_id" class="form-select" required>@foreach($stores as $s)<option value="{{ $s->id }}" {{ (string)old('store_id')===(string)$s->id?'selected':'' }}>{{ $s->name }}</option>@endforeach</select></div>
<div class="mb-3"><label class="form-label">Nama</label><input name="customer_name" value="{{ old('customer_name') }}" class="form-control" required></div>
<div class="mb-3"><label class="form-label">No WhatsApp</label><input name="customer_phone" value="{{ old('customer_phone') }}" class="form-control" required></div>
<div class="mb-3"><label class="form-label">Device (cth: iPhone 11)</label><input name="device" value="{{ old('device') }}" class="form-control" required></div>
<div class="mb-3"><label class="form-label">Keluhan</label><textarea name="complaint" class="form-control" required>{{ old('complaint') }}</textarea></div>
<div class="mb-3"><label class="form-label">Jadwal diinginkan</label><input type="date" name="schedule_date" value="{{ old('schedule_date') }}" class="form-control"></div>
<button class="btn btn-primary w-100">Kirim Booking</button>
</form>
</div></div></div></div></body></html>
