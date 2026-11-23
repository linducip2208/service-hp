# ServiceHP — Database Relationship Audit (Fase 1)

## Ringkasan tabel (33 tabel domain + pivot + framework)

Konvensi: `foreignId()->constrained()->cascadeOnDelete()` = FK + index + CASCADE.
`nullOnDelete()` = SET NULL.

| Tabel | Kunci/unik | FK → aksi hapus | Catatan |
|---|---|---|---|
| stores | `code` UNIQUE | — (root) | — |
| store_user | UNIQUE(store,user) | → CASCADE keduanya | pivot, tanpa model (via belongsToMany) |
| brands | `name` UNIQUE | — | — |
| categories | — | — | `name` **tidak unik**, tanpa index |
| suppliers | — | store → SET NULL | `code` **tidak unik** |
| customers | `phone` INDEX, `member_code` UNIQUE | store → SET NULL | `total_belanja` derivatif manual |
| promos | `code` UNIQUE | store → SET NULL | counter `used/quota` tanpa lock |
| products | `sku` UNIQUE | brand/category → SET NULL | barcode/type/is_active **tanpa index** (hot path POS) |
| cash_accounts | — | store → CASCADE | — |
| bookings | `code` UNIQUE, idx(store,status), idx(store,status,schedule) | store → CASCADE | `ticket_id` **tanpa FK/index** |
| purchases | `invoice_no` UNIQUE | store CASCADE, supplier/user SET NULL | — |
| sales | `invoice_no` UNIQUE, idx(store,created), idx(payment_status) | store CASCADE, user/customer/promo SET NULL | money derivatif manual |
| product_items | `imei` UNIQUE NULL, idx(store,status) | product/store CASCADE, supplier SET NULL | `consignment_id` **tanpa FK/relasi** |
| secondhand_purchases | — | store CASCADE, lainnya SET NULL | `imei` **tanpa unik/index** |
| consignments | `code` UNIQUE | store/product CASCADE, supplier/item SET NULL | — |
| service_tickets | `ticket_no` UNIQUE, `estimated_done_at` INDEX, idx(store,status,tech) | store CASCADE, **customer CASCADE** ⚠️, technician SET NULL | `customer CASCADE` harusnya SET NULL; `booking_id` tanpa FK |
| trade_ins | — | store CASCADE, sale/customer SET NULL | `imei` bebas tanpa index |
| product_stocks | UNIQUE(product,store), idx(store,location) | keduanya CASCADE | `qty` signed tanpa floor DB |
| stock_movements | idx(product,created) | store/product CASCADE, item/user SET NULL | `from/to_store`, `ref_*` **tanpa FK/index** |
| purchase_items / sale_items | — | parent+product CASCADE | — |
| sale_payments | — | sale CASCADE, user SET NULL | `method` VARCHAR bebas (bukan enum) |
| sale_returns | — | sale/store CASCADE, item/user SET NULL | — |
| service_spareparts | — | ticket+product CASCADE | nama relasi `ticket` vs induk `spareparts` (asimetri) |
| service_logs | idx(ticket,to_status) | ticket CASCADE, user SET NULL | status string bebas |
| technician_commissions | idx(tech,status) | ticket CASCADE, **technician CASCADE** ⚠️ | hapus user = hapus ledger; harusnya SET NULL/RESTRICT |
| warranties | idx(ref_type,ref_id), idx(status,end) | customer/item SET NULL | ref manual tanpa FK |
| cash_transactions | idx(store,type,cat,created), idx(ref_type,ref_id) | store CASCADE, akun/user SET NULL | ref manual |
| expenses | — | store CASCADE, user SET NULL | `expense_date`, `category` **tanpa index** |
| debts | idx(store,status,due), idx(ref) | store CASCADE | ref manual; `party_name` denormalisasi |
| wa_logs | idx(store,type,created), idx(type,phone) | store SET NULL | status antri/terkirim/gagal |

## Temuan integritas (prioritas)

1. **Hapus Store = hapus semua riwayat** (sales/purchases/service/cash/debt/expenses → CASCADE). Pertimbangkan RESTRICT + alur arsip.
2. **Hapus Customer = hapus tiket service** (CASCADE) tapi sales dipertahankan (SET NULL) — inkonsisten; samakan ke SET NULL.
3. **Hapus teknisi (User) = hapus ledger komisi** (CASCADE) — wajib SET NULL/RESTRICT.
4. **Stok negatif & IMEI ganda** tidak dijaga di DB (qty signed; hanya `product_items.imei` unik; IMEI secondhand/service/tradein bebas).
5. **Link tanpa FK**: `bookings.ticket_id`, `service_tickets.booking_id`, `product_items.consignment_id`, `stock_movements.from/to_store` + `ref_*`, semua `ref_type/ref_id` polimorfik manual (FQCN string — rename class = riwayat yatim).
6. **Relasi model hilang**: `Product→serviceSpareparts/consignments/secondhand`, `Customer→secondhand`, `Booking→ticket`, `ProductItem→consignment/saleItems`, `Store→bookings/consignments/secondhand/tradeIns/returns/movements/expenses/debts/wa/cash`, `User→payments/returns/movements/cash/expenses/logs`, `Debt→counterparty`.
7. **Derivatif tanpa invariant**: total/paid/remaining, balance, total_belanja, hutang, used/quota — hanya dijaga controller + `DB::transaction` (52 titik, nol di model). Tanpa soft delete: hapus = hilang permanen.
8. **Snapshot denormalisasi** (nama/tipe/party/deskripsi) — perlakukan immutable-after-write.

## Rekomendasi index (nilai tertinggi dulu)

1. `bookings.ticket_id`, `service_tickets.booking_id` (single).
2. `product_items.consignment_id` (+ FK nullOnDelete + relasi).
3. `stock_movements(from_store_id)`, `(to_store_id)`, `(ref_type,ref_id)`.
4. `secondhand_imei`, `service_imei`, `tradein_imei` (non-unik, investigasi).
5. `expenses(expense_date)`, `(store_id,expense_date)`, `(category)`.
6. `products(barcode)`, `(type,is_active)`, `sales(customer_id,created_at)`, `purchases(status)`, `sale_items(product_id)`.
