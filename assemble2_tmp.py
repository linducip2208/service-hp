import io, os, shutil

ROOT = r"D:\project laravel\servicehp"
NM = os.path.join(ROOT, "node_modules")
V = os.path.join(ROOT, "public", "vendor")

def cp(src, dst):
    os.makedirs(os.path.dirname(dst), exist_ok=True)
    shutil.copyfile(src, dst)
    print("OK %s (%d B)" % (os.path.relpath(dst, ROOT), os.path.getsize(dst)))

os.makedirs(os.path.join(V, "chart"), exist_ok=True)
os.makedirs(os.path.join(V, "qrcode"), exist_ok=True)
shutil.copyfile(os.path.join(ROOT, "public", "vendor-tmp-chart.js"), os.path.join(V, "chart", "chart.umd.min.js"))
print("OK public/vendor/chart/chart.umd.min.js")
cp(os.path.join(NM, "jsbarcode", "dist", "JsBarcode.all.min.js"), os.path.join(V, "jsbarcode", "JsBarcode.all.min.js"))
os.makedirs(os.path.join(V, "chart"), exist_ok=True)
os.makedirs(os.path.join(V, "qrcode"), exist_ok=True)
shutil.copyfile(os.path.join(ROOT, "public", "vendor-tmp-qrcode.js"), os.path.join(V, "qrcode", "qrcode.min.js"))
print("OK public/vendor/qrcode/qrcode.min.js")
cp(os.path.join(NM, "html2canvas", "dist", "html2canvas.min.js"), os.path.join(V, "html2canvas", "html2canvas.min.js"))
for w in (400, 500, 600, 700, 800):
    cp(os.path.join(NM, "@fontsource", "plus-jakarta-sans", "files", "plus-jakarta-sans-latin-%d-normal.woff2" % w),
       os.path.join(V, "fonts", "plus-jakarta-sans-latin-%d-normal.woff2" % w))
with io.open(os.path.join(V, "fonts", "fonts.css"), "w", encoding="utf-8", newline="") as f:
    f.write("/* Plus Jakarta Sans self-hosted (latin) — sumber: @fontsource/plus-jakarta-sans */\n")
    for w in (400, 500, 600, 700, 800):
        f.write("@font-face{font-family:'Plus Jakarta Sans';font-style:normal;font-display:swap;font-weight:%d;src:url('./plus-jakarta-sans-latin-%d-normal.woff2') format('woff2');}\n" % (w, w))
print("OK public/vendor/fonts/fonts.css")
with io.open(os.path.join(V, "README.md"), "w", encoding="utf-8", newline="") as f:
    f.write("# Vendor lokal ( bebas CDN )\n\nDisalin dari npm/CDN resmi, versi dikunci:\n\n- tabler 1.0.0-beta20 — https://cdn.jsdelivr.net/npm/@tabler/core@1.0.0-beta20/…\n- icons-webfont 3.52.0 — hanya file woff2 yang direferensikan tabler-icons.min.css\n- chart.js 4.4.1 — chart.umd.min.js\n- jsbarcode 3.11.6 — JsBarcode.all.min.js\n- qrcodejs 1.0.0 (cdnjs) — qrcode.min.js\n- html2canvas 1.4.1 — html2canvas.min.js\n- Plus Jakarta Sans latin 400–800 — paket @fontsource/plus-jakarta-sans\n\nJangan edit manual; ulangi unduhan bila naik versi.\n")
print("OK public/vendor/README.md")
