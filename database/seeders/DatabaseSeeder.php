<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\CashAccount;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Promo;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // --- Toko ---
        $pusat = Store::firstOrCreate(['code' => 'PST'], [
            'name' => 'ServiceHP Pusat', 'address' => 'Jl. Merdeka No. 1, Jakarta',
            'phone' => '021-111111', 'is_center' => true, 'is_active' => true,
        ]);
        $cabang = Store::firstOrCreate(['code' => 'CBG1'], [
            'name' => 'ServiceHP Cabang Bekasi', 'address' => 'Jl. Ahmad Yani No. 88, Bekasi',
            'phone' => '021-222222', 'is_center' => false, 'is_active' => true,
        ]);
        $stores = [$pusat, $cabang];

        // --- Brand ---
        $brands = [];
        foreach (['Apple', 'Samsung', 'Xiaomi', 'Oppo', 'Vivo'] as $b) {
            $brands[$b] = Brand::firstOrCreate(['name' => $b]);
        }

        // --- Kategori ---
        $cats = [];
        foreach ([
            ['name' => 'HP Baru', 'type' => 'hp'],
            ['name' => 'HP Bekas', 'type' => 'hp'],
            ['name' => 'Aksesoris', 'type' => 'aksesoris'],
            ['name' => 'Sparepart', 'type' => 'sparepart'],
            ['name' => 'Pulsa & Paket Data', 'type' => 'pulsa'],
        ] as $c) {
            $cats[$c['name']] = Category::firstOrCreate(['name' => $c['name']], ['type' => $c['type']]);
        }

        // --- 15 Produk contoh ---
        $products = [
            ['sku' => 'HP-IP14-128', 'brand' => 'Apple', 'cat' => 'HP Baru', 'name' => 'iPhone 14 128GB', 'type' => 'hp_baru', 'buy' => 11500000, 'sell' => 12999000, 'stock' => 10],
            ['sku' => 'HP-IP13-128-BKS', 'brand' => 'Apple', 'cat' => 'HP Bekas', 'name' => 'iPhone 13 128GB (Bekas)', 'type' => 'hp_bekas', 'buy' => 6500000, 'sell' => 7999000, 'stock' => 5],
            ['sku' => 'HP-SS23-256', 'brand' => 'Samsung', 'cat' => 'HP Baru', 'name' => 'Samsung Galaxy S23 256GB', 'type' => 'hp_baru', 'buy' => 10500000, 'sell' => 11999000, 'stock' => 8],
            ['sku' => 'HP-SSA54-128', 'brand' => 'Samsung', 'cat' => 'HP Baru', 'name' => 'Samsung Galaxy A54 128GB', 'type' => 'hp_baru', 'buy' => 5200000, 'sell' => 5999000, 'stock' => 12],
            ['sku' => 'HP-XM13-256', 'brand' => 'Xiaomi', 'cat' => 'HP Baru', 'name' => 'Xiaomi 13 256GB', 'type' => 'hp_baru', 'buy' => 6800000, 'sell' => 7499000, 'stock' => 10],
            ['sku' => 'HP-XMRN12-128', 'brand' => 'Xiaomi', 'cat' => 'HP Baru', 'name' => 'Redmi Note 12 128GB', 'type' => 'hp_baru', 'buy' => 2400000, 'sell' => 2799000, 'stock' => 20],
            ['sku' => 'HP-OPR11-256', 'brand' => 'Oppo', 'cat' => 'HP Baru', 'name' => 'Oppo Reno 11 256GB', 'type' => 'hp_baru', 'buy' => 6500000, 'sell' => 7199000, 'stock' => 8],
            ['sku' => 'HP-VVV29-256', 'brand' => 'Vivo', 'cat' => 'HP Baru', 'name' => 'Vivo V29 256GB', 'type' => 'hp_baru', 'buy' => 5800000, 'sell' => 6499000, 'stock' => 8],
            ['sku' => 'AKS-CAS-UNI', 'brand' => 'Samsung', 'cat' => 'Aksesoris', 'name' => 'Casing HP Universal', 'type' => 'aksesoris', 'buy' => 15000, 'sell' => 35000, 'stock' => 100],
            ['sku' => 'AKS-TG-UNI', 'brand' => 'Xiaomi', 'cat' => 'Aksesoris', 'name' => 'Tempered Glass Universal', 'type' => 'aksesoris', 'buy' => 8000, 'sell' => 25000, 'stock' => 150],
            ['sku' => 'AKS-CHR-25W', 'brand' => 'Samsung', 'cat' => 'Aksesoris', 'name' => 'Charger 25W Fast Charging', 'type' => 'aksesoris', 'buy' => 75000, 'sell' => 129000, 'stock' => 40],
            ['sku' => 'SPR-LCD-IP11', 'brand' => 'Apple', 'cat' => 'Sparepart', 'name' => 'LCD iPhone 11 Original', 'type' => 'sparepart', 'buy' => 450000, 'sell' => 750000, 'stock' => 6],
            ['sku' => 'SPR-BAT-SS', 'brand' => 'Samsung', 'cat' => 'Sparepart', 'name' => 'Baterai Samsung Universal', 'type' => 'sparepart', 'buy' => 120000, 'sell' => 220000, 'stock' => 15],
            ['sku' => 'PLS-50K', 'brand' => 'Xiaomi', 'cat' => 'Pulsa & Paket Data', 'name' => 'Pulsa Elektrik 50rb', 'type' => 'pulsa', 'buy' => 50000, 'sell' => 52000, 'stock' => 0],
            ['sku' => 'PKT-30GB', 'brand' => 'Oppo', 'cat' => 'Pulsa & Paket Data', 'name' => 'Paket Data 30GB', 'type' => 'paket_data', 'buy' => 85000, 'sell' => 95000, 'stock' => 0],
        ];
        foreach ($products as $p) {
            $product = Product::firstOrCreate(['sku' => $p['sku']], [
                'brand_id' => $brands[$p['brand']]->id,
                'category_id' => $cats[$p['cat']]->id,
                'name' => $p['name'], 'type' => $p['type'],
                'purchase_price' => $p['buy'], 'selling_price' => $p['sell'],
                'min_stock' => 5, 'is_active' => true,
            ]);
            foreach ($stores as $s) {
                ProductStock::firstOrCreate(
                    ['store_id' => $s->id, 'product_id' => $product->id],
                    ['qty' => $p['stock']]
                );
            }
        }

        // --- Akun kas default per toko ---
        foreach ($stores as $s) {
            CashAccount::firstOrCreate(['store_id' => $s->id, 'name' => 'Kas Tunai'], [
                'balance' => 10000000, 'is_default' => true,
            ]);
            CashAccount::firstOrCreate(['store_id' => $s->id, 'name' => 'Bank BCA'], [
                'balance' => 25000000, 'is_default' => false,
            ]);
        }

        // --- Promo ---
        Promo::firstOrCreate(['code' => 'WELCOME10'], [
            'store_id' => null, 'name' => 'Diskon 10% Pelanggan Baru',
            'type' => 'diskon_persen', 'value' => 10, 'min_belanja' => 100000,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonths(6)->toDateString(),
            'quota' => 1000, 'used' => 0, 'is_active' => true,
        ]);

        // --- User ---
        $users = [
            ['name' => 'Admin', 'email' => 'admin@servicehp.id', 'role' => 'superadmin'],
            ['name' => 'Owner', 'email' => 'owner@servicehp.id', 'role' => 'owner'],
            ['name' => 'Kasir', 'email' => 'kasir@servicehp.id', 'role' => 'kasir'],
            ['name' => 'Teknisi', 'email' => 'teknisi@servicehp.id', 'role' => 'teknisi'],
        ];
        foreach ($users as $u) {
            $user = User::firstOrCreate(['email' => $u['email']], [
                'name' => $u['name'],
                'password' => Hash::make('password'),
                'role' => $u['role'],
                'phone' => '0812000000',
                'active_store_id' => $pusat->id,
                'commission_rate' => $u['role'] === 'teknisi' ? 10 : 0,
                'is_active' => true,
            ]);
            // Assign ke semua toko (idempotent)
            foreach ($stores as $s) {
                if (!$user->stores()->where('stores.id', $s->id)->exists()) {
                    $user->stores()->attach($s->id, ['is_default' => $s->id === $pusat->id]);
                }
            }
        }

        // --- Customer contoh ---
        foreach ([
            ['name' => 'Budi Santoso', 'phone' => '0812111111', 'member_code' => 'MBR-001'],
            ['name' => 'Siti Aminah', 'phone' => '0812222222', 'member_code' => 'MBR-002'],
            ['name' => 'Agus Wijaya', 'phone' => '0812333333', 'member_code' => 'MBR-003'],
        ] as $c) {
            Customer::firstOrCreate(['phone' => $c['phone']], $c + [
                'store_id' => $pusat->id, 'address' => 'Jakarta', 'total_belanja' => 0,
            ]);
        }

        // --- Supplier contoh ---
        foreach ([
            ['code' => 'SUP-001', 'name' => 'PT Ponsel Jaya Abadi', 'phone' => '021-333333'],
            ['code' => 'SUP-002', 'name' => 'CV Aksesoris Murah', 'phone' => '021-444444'],
            ['code' => 'SUP-003', 'name' => 'Grosir Sparepart Glodok', 'phone' => '021-555555'],
        ] as $s) {
            Supplier::firstOrCreate(['code' => $s['code']], $s + [
                'store_id' => $pusat->id, 'address' => 'Jakarta', 'hutang' => 0,
            ]);
        }
        // Data demo showroom NONAKTIF default — manual: php artisan db:seed --class=DemoTransactionSeeder
    }
}
