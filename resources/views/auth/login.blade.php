<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/>
  <meta name="theme-color" content="#0f2440"/>
  <title>Login — ServiceHP</title>
  <link rel="manifest" href="/manifest.webmanifest">
  <link rel="icon" href="/icons/icon-192.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css" rel="stylesheet"/>
  <link href="/css/corporate.css" rel="stylesheet"/>
</head>
<body>
<div class="corp-login-wrap">
  <div class="corp-login-brand">
    <div>
      <div class="d-flex align-items-center gap-2">
        <span class="corp-brandmark">S</span>
        <span><span class="corp-brandname" style="font-size:1.25rem">ServiceHP</span><br><span class="corp-storename">POS · Inventory · Service Center</span></span>
      </div>
      <h2 class="mt-4 mb-2" style="font-weight:800;letter-spacing:-.02em;line-height:1.2">Operasional toko HP<br>dalam satu dasbor.</h2>
      <p style="color:#aebdd4;max-width:34ch">Kasir, stok IMEI, tiket service, teknisi, keuangan, dan laporan multi-cabang — tertib dan teraudit.</p>
      <div class="corp-login-points">
        <div class="corp-login-point"><i class="ti ti-shopping-cart"></i><span>POS cepat: scan barcode/IMEI, split payment, tahan struk.</span></div>
        <div class="corp-login-point"><i class="ti ti-tool"></i><span>Service center: tiket, estimasi, QC, garansi, notif WA.</span></div>
        <div class="corp-login-point"><i class="ti ti-chart-bar"></i><span>Laporan laba rugi, arus kas, target omzet per toko.</span></div>
      </div>
    </div>
    <div class="small" style="color:#8ea1bd">v{{ env('APP_VERSION', '2026.11') }} · Akses internal karyawan. Jaga kerahasiaan akun Anda.</div>
  </div>
  <div class="corp-login-form">
    <div class="corp-login-card">
      <h1 class="h2 mb-1" style="font-weight:800;letter-spacing:-.02em">Masuk ke akun Anda</h1>
      <p class="text-secondary mb-4">Gunakan email dan password yang diberikan owner/admin.</p>
      @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
      <form method="POST" action="{{ route('login.post') }}">@csrf
        @php $isLocalDemo = app()->isLocal() && config('app.debug'); @endphp
        <div class="mb-3"><label class="form-label">Email <span class="text-danger">*</span></label><input type="email" name="email" class="form-control" value="{{ old('email', $isLocalDemo ? 'admin@servicehp.id' : '') }}" placeholder="nama@toko.id" required autofocus autocomplete="username"></div>
        <div class="mb-2"><label class="form-label">Password <span class="text-danger">*</span></label><input type="password" name="password" class="form-control" value="{{ $isLocalDemo ? 'password' : '' }}" placeholder="••••••••" required autocomplete="current-password"></div>
        <div class="mb-3 d-flex justify-content-between align-items-center"><label class="form-check mb-0"><input type="checkbox" name="remember" class="form-check-input"/><span class="form-check-label">Ingat saya</span></label></div>
        <div class="form-footer"><button type="submit" class="btn btn-primary w-100">Masuk <i class="ti ti-arrow-right ms-1"></i></button></div>
      </form>
      <div class="text-center text-secondary mt-4 small">Demo lokal: <code>admin@servicehp.id / password</code></div>
    </div>
  </div>
</div>
</body>
</html>
