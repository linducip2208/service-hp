<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Halaman Tidak Ditemukan — ServiceHP</title>
    <link
        href="/vendor/tabler/tabler.min.css"
        rel="stylesheet"
    />
</head>
<body class="border-top-wide border-primary">
    <div class="page page-center">
        <div class="container-tight py-4">
            <div class="text-center mb-3">
                <a href="{{ url('/') }}" class="text-decoration-none fs-2">
                    <i class="ti ti-device-mobile me-1"></i> <strong>ServiceHP</strong>
                </a>
            </div>
            <div class="card card-md">
                <div class="card-body text-center p-5">
                    <div class="display-1 fw-bold text-primary mb-2">
                        404
                    </div>
                    <h1 class="h2 mb-2">
                        Halaman tidak ditemukan
                    </h1>
                    <p class="text-secondary mb-4">
                        Maaf, alamat yang Anda tuju tidak ada atau sudah dipindahkan.
                        Periksa kembali alamatnya, atau kembali ke halaman utama.
                    </p>
                    <div class="d-flex flex-column flex-sm-row gap-2 justify-content-center">
                        <a href="{{ url('/') }}" class="btn btn-primary">
                            Kembali ke Dashboard
                        </a>
                        <a href="{{ url('/track') }}" class="btn btn-outline-secondary">
                            Lacak Service
                        </a>
                    </div>
                </div>
            </div>
            <div class="text-center text-secondary mt-3">
                <small>Kode error: 404</small>
            </div>
        </div>
    </div>
</body>
</html>
