import io, os

ROOT = r"D:\project laravel\servicehp\resources\views"
MAP = [
    ("https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/css/tabler.min.css", "/vendor/tabler/tabler.min.css"),
    ("https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/dist/js/tabler.min.js", "/vendor/tabler/tabler.min.js"),
    ("https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css", "/vendor/icons/tabler-icons.min.css"),
    ("https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js", "/vendor/chart/chart.umd.min.js"),
    ("https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js", "/vendor/jsbarcode/JsBarcode.all.min.js"),
    ("https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js", "/vendor/qrcode/qrcode.min.js"),
    ("https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js", "/vendor/html2canvas/html2canvas.min.js"),
]
# Google Fonts -> lokal (hapus preconnect)
GF = "https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"

changed = []
for dirpath, _, files in os.walk(ROOT):
    for f in files:
        if not f.endswith(".blade.php"):
            continue
        full = os.path.join(dirpath, f)
        with io.open(full, encoding="utf-8") as fh:
            src = fh.read()
        orig = src
        for old, new in MAP:
            src = src.replace(old, new)
        if GF in src:
            src = src.replace('<link rel="preconnect" href="https://fonts.googleapis.com">\n', '')
            src = src.replace('<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>\n', '')
            src = src.replace(GF, '/vendor/fonts/fonts.css')
        if src != orig:
            with io.open(full, "w", encoding="utf-8", newline="") as fh:
                fh.write(src)
            changed.append(os.path.relpath(full, ROOT).replace(os.sep, "/"))

print("FILES=%d" % len(changed))
for c in changed:
    print(c)
