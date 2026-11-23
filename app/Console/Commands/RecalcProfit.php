<?php

namespace App\Console\Commands;

use App\Models\SaleItem;
use Illuminate\Console\Command;

class RecalcProfit extends Command
{
    /**
     * Hitung ulang profit sale_items yang masih 0/null dari harga beli master.
     * HANYA mengisi yang kosong — tidak menimpa profit yang sudah terisi.
     * Contoh: php artisan profit:recalc
     *          php artisan profit:recalc --store=1
     *          php artisan profit:recalc --dry-run
     */
    protected $signature = 'profit:recalc {--store= : Batasi ke 1 toko (id)} {--dry-run : Hitung saja tanpa menyimpan}';

    protected $description = 'Isi profit sale_items yang masih 0/null dari harga beli master (tanpa menimpa yang sudah ada)';

    public function handle(): int
    {
        $storeOpt = trim((string) $this->option('store'));
        $dry = (bool) $this->option('dry-run');

        $q = SaleItem::with(['product', 'productItem', 'sale'])
            ->where(function ($w) {
                $w->whereNull('profit')->orWhere('profit', 0);
            });
        if ($storeOpt !== '') {
            $q->whereHas('sale', fn ($s) => $s->where('store_id', $storeOpt));
        }

        $scanned = 0;
        $filled = 0;
        $skippedNoModal = 0;
        $totalProfit = 0.0;

        $q->chunkById(500, function ($items) use (&$scanned, &$filled, &$skippedNoModal, &$totalProfit, $dry) {
            foreach ($items as $it) {
                $scanned++;
                // Modal: harga beli unit IMEI bila ada & > 0, fallback ke harga beli produk.
                $modal = 0.0;
                if ($it->product_item_id && $it->productItem && (float) $it->productItem->purchase_price > 0) {
                    $modal = (float) $it->productItem->purchase_price;
                } elseif ($it->product) {
                    $modal = (float) $it->product->purchase_price;
                }
                if ($modal <= 0) {
                    $skippedNoModal++;
                    continue;
                }
                $qty = (int) $it->qty ?: 1;
                $profit = ((float) $it->price - $modal) * $qty - (float) ($it->discount ?? 0);
                if (! $dry) {
                    $it->profit = $profit;
                    $it->save();
                }
                $filled++;
                $totalProfit += $profit;
            }
        });

        $this->info('Dipindai: ' . $scanned . ' baris (profit 0/null)' . ($dry ? ' [DRY-RUN]' : ''));
        $this->info('Terisi: ' . $filled . ' baris' . ($dry ? ' (simulasi, tidak disimpan)' : ''));
        $this->info('Total profit terisi: Rp ' . number_format($totalProfit, 0, ',', '.'));
        if ($skippedNoModal) {
            $this->warn('Dilewati (tanpa harga beli master): ' . $skippedNoModal . ' baris.');
        }

        return 0;
    }
}
