<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'loginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login.post');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Public: lacak service + booking online
Route::get('/track', [\App\Http\Controllers\PublicController::class, 'trackForm'])->name('track.form');
Route::post('/track', [\App\Http\Controllers\PublicController::class, 'track'])->middleware('throttle:30,1')->name('track.result');
Route::get('/booking', [\App\Http\Controllers\PublicController::class, 'bookingForm'])->name('booking.form');
Route::post('/booking', [\App\Http\Controllers\PublicController::class, 'bookingStore'])->middleware('throttle:10,1')->name('booking.store');

// Layar antrean TV — publik tanpa auth (hanya nomor tiket + status + waktu).
Route::get('/display', [\App\Http\Controllers\DisplayController::class, 'index'])->name('display');

// Nomor antrean walk-in — publik tanpa auth (counter harian file-based, tanpa tabel).
Route::post('/queue/take', [\App\Http\Controllers\QueueController::class, 'take'])->middleware('throttle:20,1')->name('queue.take');

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/activity', [DashboardController::class, 'activity'])->name('activity');
    Route::get('/search', [DashboardController::class, 'search'])->name('search');
    Route::get('/activity/log', [\App\Http\Controllers\ActivityController::class, 'index'])->name('activity.log');
    // Kesehatan Data (read-only + 4 aksi aman) — khusus superadmin/owner (cek di controller)
    Route::get('/health', [DashboardController::class, 'health'])->name('health');
    Route::post('/health/fix-all-safe', [DashboardController::class, 'fixAllSafe'])->name('health.fix_all_safe');
    Route::post('/health/units/{id}/mark-sold', [DashboardController::class, 'fixUnit'])->name('health.unit.sold');
    Route::post('/health/units/{id}/mark-available', [DashboardController::class, 'fixServiceUnit'])->name('health.unit.available');
    Route::post('/health/commissions/{id}/delete', [DashboardController::class, 'fixCommission'])->name('health.commission.delete');
    Route::post('/health/warranties/{id}/mark-expired', [DashboardController::class, 'fixWarranty'])->name('health.warranty.expired');
    Route::get('/setup', [DashboardController::class, 'setup'])->name('setup');
    Route::get('/notifications/count', [DashboardController::class, 'notificationCount'])->name('notifications.count');
    Route::get('/help', fn() => view('help.index'))->name('help');
    Route::post('/store/{id}', [DashboardController::class, 'switchStore'])->name('store.switch');

    // POS & Penjualan
    Route::get('/pos', [\App\Http\Controllers\PosController::class, 'index'])->name('pos.index');
    Route::post('/pos', [\App\Http\Controllers\PosController::class, 'store'])->name('pos.store');
    Route::get('/pos/print/{sale}', [\App\Http\Controllers\PosController::class, 'print'])->name('pos.print');
    // closing SEBELUM resource agar tak tertabrak sales.show ({sale}=closing); delegasi di show tetap sebagai pengaman.
    Route::get('/sales/closing', [\App\Http\Controllers\SaleController::class, 'closing'])->name('sales.closing');
    Route::post('/sales/closing/wa', [\App\Http\Controllers\SaleController::class, 'closingWa'])->name('sales.closing.wa');
    Route::post('/sales/closing/digest', [\App\Http\Controllers\SaleController::class, 'closingDigest'])->name('sales.closing.digest');
    Route::get('/sales/invoices/bulk', [\App\Http\Controllers\SaleController::class, 'bulkInvoices'])->name('sales.bulkInvoices');
    Route::get('/sales/agreements/bulk', [\App\Http\Controllers\SaleController::class, 'bulkAgreements'])->name('sales.bulkAgreements');
    Route::get('/sales/{sale}/invoice', [\App\Http\Controllers\SaleController::class, 'invoice'])->name('sales.invoice');
    Route::get('/sales/{sale}/agreement', [\App\Http\Controllers\SaleController::class, 'agreement'])->name('sales.agreement');
    Route::get('/sales/payments/{payment}/receipt', [\App\Http\Controllers\SaleController::class, 'paymentReceipt'])->name('sales.payments.receipt');
    Route::resource('sales', \App\Http\Controllers\SaleController::class)->only(['index', 'show', 'destroy']);
    Route::post('/sales/{sale}/pay', [\App\Http\Controllers\SaleController::class, 'pay'])->name('sales.pay');
    Route::resource('returns', \App\Http\Controllers\ReturnController::class)->only(['index', 'store']);
    Route::resource('tradeins', \App\Http\Controllers\TradeInController::class)->only(['index', 'store']);
    Route::resource('secondhand', \App\Http\Controllers\SecondhandController::class)->only(['index', 'store', 'update']);
    Route::resource('consignments', \App\Http\Controllers\ConsignmentController::class)->only(['index', 'store', 'update']);
    Route::post('/consignments/{consignment}/settle', [\App\Http\Controllers\ConsignmentController::class, 'settle'])->name('consignments.settle');

    // Inventory (export/import didaftarkan SEBELUM resource agar tak tertabrak {product})
    Route::get('/products/bulk', [\App\Http\Controllers\ProductController::class, 'bulkPrice'])->name('products.bulk');
    Route::post('/products/bulk', [\App\Http\Controllers\ProductController::class, 'bulkPriceUpdate'])->name('products.bulk.update');
    Route::post('/products/bulk-assign', [\App\Http\Controllers\ProductController::class, 'bulkAssign'])->name('products.bulk.assign');
    Route::get('/products/reorder', [\App\Http\Controllers\ProductController::class, 'reorder'])->name('products.reorder');
    Route::get('/products/export', [\App\Http\Controllers\ProductController::class, 'export'])->name('products.export');
    Route::get('/products/template', [\App\Http\Controllers\ProductController::class, 'template'])->name('products.template');
    Route::post('/products/import', [\App\Http\Controllers\ProductController::class, 'import'])->name('products.import');
    Route::post('/products/merge', [\App\Http\Controllers\ProductController::class, 'merge'])->name('products.merge');
    Route::post('/products/merge-exact', [\App\Http\Controllers\ProductController::class, 'mergeExact'])->name('products.merge-exact');
    Route::post('/products/{product}/min-stock', [\App\Http\Controllers\ProductController::class, 'updateMinStock'])->name('products.min-stock');
    Route::post('/products/{product}/duplicate', [\App\Http\Controllers\ProductController::class, 'duplicate'])->name('products.duplicate');
    Route::post('/products/reorder/wa', [\App\Http\Controllers\ProductController::class, 'reorderWa'])->name('products.reorder.wa');
    Route::resource('products', \App\Http\Controllers\ProductController::class);
    Route::get('/units/template', [\App\Http\Controllers\UnitController::class, 'template'])->name('units.template');
    Route::post('/units/import', [\App\Http\Controllers\UnitController::class, 'import'])->name('units.import');
    Route::get('/units', [\App\Http\Controllers\UnitController::class, 'index'])->name('units.index');
    Route::post('/units', [\App\Http\Controllers\UnitController::class, 'store'])->name('units.store');
    Route::get('/units/{unit}/timeline', [\App\Http\Controllers\UnitController::class, 'timeline'])->name('units.timeline');
    Route::get('/stocks/valuation', [\App\Http\Controllers\StockController::class, 'valuation'])->name('stocks.valuation');
    Route::get('/stocks/deadstock', [\App\Http\Controllers\StockController::class, 'deadstock'])->name('stocks.deadstock');
    Route::get('/stocks/opname', [\App\Http\Controllers\StockController::class, 'opname'])->name('stocks.opname');
    Route::post('/stocks/opname/import', [\App\Http\Controllers\StockController::class, 'opnameImport'])->name('stocks.opname.import');
    Route::post('/stocks/opname', [\App\Http\Controllers\StockController::class, 'opnameStore'])->name('stocks.opname.store');
    Route::get('/stocks/mutations/surat-jalan', [\App\Http\Controllers\StockController::class, 'suratJalan'])->name('stocks.mutations.surat');
    Route::get('/stocks/mutations', [\App\Http\Controllers\StockController::class, 'mutations'])->name('stocks.mutations');
    Route::post('/stocks/mutations', [\App\Http\Controllers\StockController::class, 'mutate'])->name('stocks.mutate');

    // Service (report + bulk didaftarkan SEBELUM resource agar tak tertabrak {service})
    // Kanban operasional SEBELUM resource agar tak tertabrak {service}
    Route::get('/services/kanban', [\App\Http\Controllers\ServiceController::class, 'kanban'])->name('services.kanban');
    Route::get('/services/report', [\App\Http\Controllers\ServiceController::class, 'report'])->name('services.report');
    Route::get('/services/bulk-tickets', [\App\Http\Controllers\ServiceController::class, 'printBulkTickets'])->name('services.bulkTickets');
    Route::post('/services/transfer-selected', [\App\Http\Controllers\ServiceController::class, 'bulkTransferStore'])->name('services.bulkTransfer');
    Route::resource('services', \App\Http\Controllers\ServiceController::class);
    Route::post('/services/{service}/status', [\App\Http\Controllers\ServiceController::class, 'updateStatus'])->name('services.status');
    Route::post('/services/{service}/transfer', [\App\Http\Controllers\ServiceController::class, 'transferStore'])->name('services.transfer');
    Route::post('/services/{service}/priority', [\App\Http\Controllers\ServiceController::class, 'updatePriority'])->name('services.priority');
    Route::post('/services/{service}/sparepart', [\App\Http\Controllers\ServiceController::class, 'addSparepart'])->name('services.sparepart');
    Route::delete('/services/spareparts/{sparepart}', [\App\Http\Controllers\ServiceController::class, 'removeSparepart'])->name('services.sparepart.destroy');
    Route::post('/services/remind-pickup-selected', [\App\Http\Controllers\ServiceController::class, 'bulkPickupRemind'])->name('services.bulkPickupRemind');
    Route::post('/services/remind-technician-selected', [\App\Http\Controllers\ServiceController::class, 'bulkTechnicianRemind'])->name('services.bulkTechnicianRemind');
    Route::post('/services/priority-selected', [\App\Http\Controllers\ServiceController::class, 'bulkPriority'])->name('services.bulkPriority');
    Route::post('/services/technician-selected', [\App\Http\Controllers\ServiceController::class, 'bulkTechnician'])->name('services.bulkTechnician');
    Route::post('/services/estimasi-selected', [\App\Http\Controllers\ServiceController::class, 'bulkEstimasi'])->name('services.bulkEstimasi');
    Route::get('/services/{service}/print', [\App\Http\Controllers\ServiceController::class, 'printTicket'])->name('services.print');
    Route::get('/warranties', [\App\Http\Controllers\WarrantyController::class, 'index'])->name('warranties.index');
    Route::post('/warranties/{warranty}/claim', [\App\Http\Controllers\WarrantyController::class, 'claim'])->name('warranties.claim');
    Route::post('/warranties/{warranty}/ticket', [\App\Http\Controllers\WarrantyController::class, 'toTicket'])->name('warranties.ticket');
    Route::get('/warranties/cards', [\App\Http\Controllers\WarrantyController::class, 'bulkCards'])->name('warranties.cards');
    Route::post('/warranties/remind-selected', [\App\Http\Controllers\WarrantyController::class, 'bulkRemind'])->name('warranties.bulkRemind');
    Route::post('/warranties/claim-selected', [\App\Http\Controllers\WarrantyController::class, 'bulkClaim'])->name('warranties.bulkClaim');
    Route::post('/warranties/extend-selected', [\App\Http\Controllers\WarrantyController::class, 'bulkExtend'])->name('warranties.bulkExtend');
    Route::get('/warranties/{warranty}/card', [\App\Http\Controllers\WarrantyController::class, 'card'])->name('warranties.card');
    // Kalender booking SEBELUM resource agar tak tertabrak bookings.show (bila ada show {booking})
    Route::get('/bookings/calendar', [\App\Http\Controllers\BookingController::class, 'calendar'])->name('bookings.calendar');
    Route::post('/bookings/confirm-selected', [\App\Http\Controllers\BookingController::class, 'bulkConfirm'])->name('bookings.bulkConfirm');
    Route::post('/bookings/cancel-selected', [\App\Http\Controllers\BookingController::class, 'bulkCancel'])->name('bookings.bulkCancel');
    Route::post('/bookings/remind-selected', [\App\Http\Controllers\BookingController::class, 'bulkRemind'])->name('bookings.bulkRemind');
    Route::post('/bookings/transfer-selected', [\App\Http\Controllers\BookingController::class, 'bulkTransfer'])->name('bookings.bulkTransfer');
    Route::post('/bookings/reschedule-selected', [\App\Http\Controllers\BookingController::class, 'bulkReschedule'])->name('bookings.bulkReschedule');
    Route::post('/bookings/{booking}/remind', [\App\Http\Controllers\BookingController::class, 'remind'])->name('bookings.remind');
    Route::resource('bookings', \App\Http\Controllers\BookingController::class)->only(['index', 'update', 'destroy']);
    Route::get('/technicians/slips/bulk', [\App\Http\Controllers\TechnicianController::class, 'bulkSlips'])->name('technicians.bulkSlips');
    Route::get('/technicians/report', [\App\Http\Controllers\TechnicianController::class, 'report'])->name('technicians.report');
    Route::get('/technicians/{id}/slip', [\App\Http\Controllers\TechnicianController::class, 'slip'])->whereNumber('id')->name('technicians.slip');
    Route::get('/technicians/{id}/vcard', [\App\Http\Controllers\TechnicianController::class, 'vcard'])->whereNumber('id')->name('technicians.vcard');
    Route::get('/technicians', [\App\Http\Controllers\TechnicianController::class, 'index'])->name('technicians.index');
    Route::post('/technicians/pay-all', [\App\Http\Controllers\TechnicianController::class, 'payAllCommissions'])->name('technicians.payAll');
    Route::post('/technicians/{id}/pay', [\App\Http\Controllers\TechnicianController::class, 'payCommission'])->name('technicians.pay');
    Route::post('/technicians/{id}/target', [\App\Http\Controllers\TechnicianController::class, 'setTarget'])->name('technicians.target');

    // Keuangan
    Route::get('/cash', [\App\Http\Controllers\CashController::class, 'index'])->name('cash.index');
    Route::post('/cash', [\App\Http\Controllers\CashController::class, 'store'])->name('cash.store');
    Route::post('/cash/transfer', [\App\Http\Controllers\CashController::class, 'transfer'])->name('cash.transfer');
    Route::post('/cash/accounts', [\App\Http\Controllers\CashController::class, 'storeAccount'])->name('cash.accounts.store');
    Route::put('/cash/accounts/{account}', [\App\Http\Controllers\CashController::class, 'updateAccount'])->name('cash.accounts.update');
    Route::post('/expenses/budget', [\App\Http\Controllers\ExpenseController::class, 'setBudget'])->name('expenses.budget');
    Route::post('/expenses/bulk-category', [\App\Http\Controllers\ExpenseController::class, 'bulkUpdateCategory'])->name('expenses.bulkCategory');
    Route::get('/expenses/template', [\App\Http\Controllers\ExpenseController::class, 'template'])->name('expenses.template');
    Route::post('/expenses/import', [\App\Http\Controllers\ExpenseController::class, 'import'])->name('expenses.import');
    Route::resource('expenses', \App\Http\Controllers\ExpenseController::class)->only(['index', 'store', 'destroy']);
    Route::get('/debts/template', [\App\Http\Controllers\DebtController::class, 'template'])->name('debts.template');
    Route::post('/debts/import', [\App\Http\Controllers\DebtController::class, 'import'])->name('debts.import');
    Route::get('/debts/statements', [\App\Http\Controllers\DebtController::class, 'bulkStatements'])->name('debts.statements');
    Route::post('/debts/bulk-pay', [\App\Http\Controllers\DebtController::class, 'bulkPay'])->name('debts.bulkPay');
    Route::post('/debts/bulk-remind', [\App\Http\Controllers\DebtController::class, 'bulkRemind'])->name('debts.bulkRemind');
    Route::post('/debts/bulk-due-date', [\App\Http\Controllers\DebtController::class, 'bulkDueDate'])->name('debts.bulkDueDate');
    Route::get('/debts/{debt}/statement', [\App\Http\Controllers\DebtController::class, 'statement'])->name('debts.statement');
    Route::post('/debts/{debt}/due-date', [\App\Http\Controllers\DebtController::class, 'updateDueDate'])->name('debts.dueDate');
    Route::resource('debts', \App\Http\Controllers\DebtController::class)->only(['index', 'store', 'update']);
    Route::get('/reports/profit', [\App\Http\Controllers\ReportController::class, 'profit'])->name('reports.profit');
    Route::get('/cash/closing', [\App\Http\Controllers\CashController::class, 'closing'])->name('cash.closing');
    Route::post('/cash/closing/lock', [\App\Http\Controllers\CashController::class, 'setPeriodLock'])->name('cash.closing.lock');
    Route::delete('/cash/closing/lock', [\App\Http\Controllers\CashController::class, 'clearPeriodLock'])->name('cash.closing.unlock');
    // Pusat Pengingat: agregasi read-only semua reminder (owner/admin, guard di controller)
    Route::get('/reminders', [\App\Http\Controllers\ReminderController::class, 'index'])->name('reminders.index');
    Route::get('/cash/flow', [\App\Http\Controllers\CashController::class, 'flow'])->name('cash.flow');
    Route::get('/cash/vouchers', [\App\Http\Controllers\CashController::class, 'bulkVouchers'])->name('cash.vouchers');
    Route::get('/cash/{transaction}/voucher', [\App\Http\Controllers\CashController::class, 'voucher'])->whereNumber('transaction')->name('cash.voucher');
    Route::get('/purchases/template', [\App\Http\Controllers\PurchaseController::class, 'template'])->name('purchases.template');
    Route::post('/purchases/import', [\App\Http\Controllers\PurchaseController::class, 'import'])->name('purchases.import');
    Route::get('/purchases/bulk-print', [\App\Http\Controllers\PurchaseController::class, 'printBulk'])->name('purchases.bulkPrint');
    Route::resource('purchases', \App\Http\Controllers\PurchaseController::class)->only(['index', 'store', 'show']);
    Route::post('/purchases/{purchase}/pay', [\App\Http\Controllers\PurchaseController::class, 'paySupplier'])->name('purchases.pay');
    Route::post('/purchases/{purchase}/received', [\App\Http\Controllers\PurchaseController::class, 'markReceived'])->name('purchases.received');
    Route::post('/purchases/{purchase}/wa', [\App\Http\Controllers\PurchaseController::class, 'sendWa'])->name('purchases.wa');

    // Master
    Route::get('/stores/monitor', [\App\Http\Controllers\StoreController::class, 'monitor'])->name('stores.monitor');
    Route::post('/stores/targets/copy-next-year', [\App\Http\Controllers\StoreController::class, 'copyTargetsNextYear'])->name('stores.targets.copy-next-year');
    Route::post('/stores/{store}/target', [\App\Http\Controllers\StoreController::class, 'setTarget'])->name('stores.target');
    Route::resource('stores', \App\Http\Controllers\StoreController::class);
    // Import CSV pelanggan didaftarkan SEBELUM resource agar tak tertabrak {customer}
    Route::get('/customers/template', [\App\Http\Controllers\CustomerController::class, 'template'])->name('customers.template');
    Route::post('/customers/import', [\App\Http\Controllers\CustomerController::class, 'import'])->name('customers.import');
    Route::post('/customers/merge-exact', [\App\Http\Controllers\CustomerController::class, 'mergeExact'])->name('customers.merge-exact');
    Route::post('/customers/merge', [\App\Http\Controllers\CustomerController::class, 'merge'])->name('customers.merge');
    Route::post('/customers/move-store', [\App\Http\Controllers\CustomerController::class, 'moveStore'])->name('customers.move-store');
    Route::get('/customers/member-cards', [\App\Http\Controllers\CustomerController::class, 'bulkMemberCards'])->name('customers.member-cards');
    Route::get('/customers/broadcast', [\App\Http\Controllers\CustomerController::class, 'broadcast'])->name('customers.broadcast');
    Route::post('/customers/broadcast', [\App\Http\Controllers\CustomerController::class, 'broadcastSend'])->name('customers.broadcast.send');
    Route::post('/customers/{customer}/statement-wa', [\App\Http\Controllers\CustomerController::class, 'statementWa'])->name('customers.statement.wa');
    Route::get('/customers/{customer}/member-card', [\App\Http\Controllers\CustomerController::class, 'memberCard'])->name('customers.member-card');
    Route::get('/customers/{customer}/vcard', [\App\Http\Controllers\CustomerController::class, 'vcard'])->name('customers.vcard');
    Route::resource('customers', \App\Http\Controllers\CustomerController::class);
    Route::get('/suppliers/template', [\App\Http\Controllers\SupplierController::class, 'template'])->name('suppliers.template');
    Route::post('/suppliers/import', [\App\Http\Controllers\SupplierController::class, 'import'])->name('suppliers.import');
    Route::get('/suppliers/{supplier}/vcard', [\App\Http\Controllers\SupplierController::class, 'vcard'])->name('suppliers.vcard');
    Route::post('/suppliers/merge-exact', [\App\Http\Controllers\SupplierController::class, 'mergeExact'])->name('suppliers.merge-exact');
    Route::post('/suppliers/merge', [\App\Http\Controllers\SupplierController::class, 'merge'])->name('suppliers.merge');
    Route::resource('suppliers', \App\Http\Controllers\SupplierController::class);
    Route::get('/brands/template', [\App\Http\Controllers\BrandController::class, 'template'])->name('brands.template');
    Route::post('/brands/import', [\App\Http\Controllers\BrandController::class, 'import'])->name('brands.import');
    Route::post('/brands/merge-exact', [\App\Http\Controllers\BrandController::class, 'mergeExact'])->name('brands.merge-exact');
    Route::post('/brands/merge', [\App\Http\Controllers\BrandController::class, 'merge'])->name('brands.merge');
    Route::resource('brands', \App\Http\Controllers\BrandController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::get('/categories/template', [\App\Http\Controllers\CategoryController::class, 'template'])->name('categories.template');
    Route::post('/categories/import', [\App\Http\Controllers\CategoryController::class, 'import'])->name('categories.import');
    Route::post('/categories/merge-exact', [\App\Http\Controllers\CategoryController::class, 'mergeExact'])->name('categories.merge-exact');
    Route::post('/categories/merge', [\App\Http\Controllers\CategoryController::class, 'merge'])->name('categories.merge');
    Route::resource('categories', \App\Http\Controllers\CategoryController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::get('/promos/qr-sheet', [\App\Http\Controllers\PromoController::class, 'qrSheet'])->name('promos.qrSheet');
    Route::get('/promos/template', [\App\Http\Controllers\PromoController::class, 'template'])->name('promos.template');
    Route::post('/promos/import', [\App\Http\Controllers\PromoController::class, 'import'])->name('promos.import');
    Route::post('/promos/bulk-off', [\App\Http\Controllers\PromoController::class, 'bulkOff'])->name('promos.bulkOff');
    Route::post('/promos/bulk-on', [\App\Http\Controllers\PromoController::class, 'bulkOn'])->name('promos.bulkOn');
    Route::post('/promos/bulk-extend', [\App\Http\Controllers\PromoController::class, 'bulkExtend'])->name('promos.bulkExtend');
    Route::post('/promos/bulk-duplicate-all', [\App\Http\Controllers\PromoController::class, 'bulkDuplicateAll'])->name('promos.bulkDuplicateAll');
    Route::post('/promos/bulk-duplicate', [\App\Http\Controllers\PromoController::class, 'bulkDuplicate'])->name('promos.bulkDuplicate');
    Route::post('/promos/{promo}/duplicate', [\App\Http\Controllers\PromoController::class, 'duplicate'])->name('promos.duplicate');
    Route::post('/promos/{promo}/duplicate-all', [\App\Http\Controllers\PromoController::class, 'duplicateAll'])->name('promos.duplicateAll');
    Route::resource('promos', \App\Http\Controllers\PromoController::class);
    // Profil sendiri — semua role login (di luar resource users agar tak kena guard manager).
    Route::get('/profile', [\App\Http\Controllers\UserController::class, 'profile'])->name('profile');
    Route::put('/profile', [\App\Http\Controllers\UserController::class, 'updateProfile'])->name('profile.update');
    Route::post('/users/bulk-status', [\App\Http\Controllers\UserController::class, 'bulkStatus'])->name('users.bulkStatus');
    Route::post('/users/bulk-role', [\App\Http\Controllers\UserController::class, 'bulkRole'])->name('users.bulkRole');
    Route::resource('users', \App\Http\Controllers\UserController::class);
    Route::get('/settings/wa', [\App\Http\Controllers\SettingController::class, 'wa'])->name('settings.wa');
    Route::post('/settings/wa', [\App\Http\Controllers\SettingController::class, 'waSave'])->name('settings.wa.save');
    Route::post('/settings/wa/test', [\App\Http\Controllers\SettingController::class, 'waTest'])->name('settings.wa.test');
    Route::post('/settings/wa/logs/{id}/retry', [\App\Http\Controllers\SettingController::class, 'waRetry'])->name('settings.wa.retry');
    // Printer universal: browser (semua printer) + Bluetooth/USB thermal + LAN/WiFi
    Route::get('/settings/printers', [\App\Http\Controllers\PrinterController::class, 'index'])->name('settings.printers');
    Route::post('/settings/printers', [\App\Http\Controllers\PrinterController::class, 'store'])->name('settings.printers.store');
    Route::post('/settings/printers/branding', [\App\Http\Controllers\PrinterController::class, 'brandingSave'])->name('settings.printers.branding');
    Route::post('/settings/printers/{id}/default', [\App\Http\Controllers\PrinterController::class, 'setDefault'])->name('settings.printers.default');
    Route::delete('/settings/printers/{id}', [\App\Http\Controllers\PrinterController::class, 'destroy'])->name('settings.printers.destroy');
    Route::post('/settings/printers/{id}/test', [\App\Http\Controllers\PrinterController::class, 'test'])->name('settings.printers.test');
    Route::post('/settings/printers/{id}/drawer', [\App\Http\Controllers\PrinterController::class, 'testDrawer'])->name('settings.printers.drawer');
    Route::post('/printers/lan/sale/{sale}/{id}', [\App\Http\Controllers\PrinterController::class, 'sendSale'])->name('printers.lan.sale');
    Route::post('/printers/lan/service/{service}/{id}', [\App\Http\Controllers\PrinterController::class, 'sendService'])->name('printers.lan.service');
    Route::get('/printers/receipt/sale/{sale}', [\App\Http\Controllers\PrinterController::class, 'receiptSale'])->name('printers.receipt.sale');
    Route::get('/printers/receipt/service/{service}', [\App\Http\Controllers\PrinterController::class, 'receiptService'])->name('printers.receipt.service');
    Route::post('/printers/wa/sale/{sale}', [\App\Http\Controllers\PrinterController::class, 'sendWaSale'])->name('printers.wa.sale');
    Route::post('/printers/wa/service/{service}', [\App\Http\Controllers\PrinterController::class, 'sendWaService'])->name('printers.wa.service');

    // Backup Data (superadmin/owner)
    Route::get('/settings/backup', [\App\Http\Controllers\BackupController::class, 'index'])->name('backup.index');
    Route::post('/settings/backup', [\App\Http\Controllers\BackupController::class, 'store'])->name('backup.store');
    Route::post('/settings/backup/clear-cache', [\App\Http\Controllers\BackupController::class, 'clearCache'])->name('backup.clear-cache');
    Route::post('/settings/backup/demo-seed', [\App\Http\Controllers\BackupController::class, 'demoSeed'])->name('backup.demo-seed');
    Route::post('/settings/backup/demo-clean', [\App\Http\Controllers\BackupController::class, 'demoClean'])->name('backup.demo-clean');
    Route::get('/settings/backup/csv', [\App\Http\Controllers\BackupController::class, 'exportCsv'])->name('backup.csv');
    Route::get('/settings/backup/{file}/verify', [\App\Http\Controllers\BackupController::class, 'verify'])->where('file', '[A-Za-z0-9._-]+')->name('backup.verify');
    Route::get('/settings/backup/{file}', [\App\Http\Controllers\BackupController::class, 'download'])->name('backup.download');
    Route::delete('/settings/backup/{file}', [\App\Http\Controllers\BackupController::class, 'destroy'])->name('backup.destroy');
    // Audit file yatim di storage/app/public (superadmin/owner, guard di controller)
    Route::get('/settings/storage', [\App\Http\Controllers\BackupController::class, 'storage'])->name('storage.index');
    Route::delete('/settings/storage', [\App\Http\Controllers\BackupController::class, 'storageDestroy'])->name('storage.destroy');
});
