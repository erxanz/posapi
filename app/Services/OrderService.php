<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\HistoryTransaction;
use App\Models\Outlet;
use App\Models\Table;
use App\Models\StockHistory;
use App\Models\Tax;
use App\Models\Discount;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\InvoiceCounter;
use App\Events\OrderCreated;
use App\Events\OrderUpdated;

class OrderService
{
    public function createCheckoutOrder(array $validated, ?int $outletId = null): array
    {
        $user = $this->currentUser();
        $outletId ??= $validated['outlet_id'] ?? null;

        if (!$outletId && !empty($validated['table_id'])) {
            $outletId = Table::find($validated['table_id'])?->outlet_id;
        }

        $outletId ??= $user->outlet_id;
        $outlet = Outlet::findOrFail($outletId);

        if (!$this->canAccessOutlet($outlet->id)) {
            throw new \Exception('Forbidden: Anda tidak memiliki akses ke Cabang ini.');
        }

        $table = Table::where('id', $validated['table_id'])
            ->where('outlet_id', $outlet->id)
            ->firstOrFail();

        DB::beginTransaction();

        try {

            $invoice = $this->generateInvoiceNumberWithRetry($outlet->id);

            $order = Order::create([
                'outlet_id' => $outlet->id,
                'user_id' => $user->id,
                'table_id' => $table->id,
                'customer_name' => $validated['customer_name'] ?? null,
                'invoice_number' => $invoice,
                'status' => Order::STATUS_PAID,
                'total_price' => 0,
                'payment_method' => $this->normalizePaymentMethod($validated['payment_method'] ?? null),
            ]);

            // ===============================
            // LANJUT PROSES NORMAL
            // ===============================

            $this->createOrderItems($order, $validated['items'], $outlet);
            $this->handleAdjustments($order, $validated);
            $order->recalculateTotals($validated);

            $amountPaid = (int) $validated['amount_paid'];
            $payableTotal = $order->payableTotal();

            if ($amountPaid < $payableTotal) {
                throw new \Exception('Nominal bayar kurang dari total tagihan');
            }

            $this->createPayment($order, $amountPaid, $validated['payment_method']);
            $this->storeHistoryTransaction($order);
            $table->update(['status' => 'available']);

            DB::commit();

            $broadcastOrder = $order->fresh()->load('items.product', 'table', 'discount');
            if (is_null($order->user_id) && $order->payment_method !== 'qris') {
                broadcast(new OrderCreated($broadcastOrder))->toOthers();
            }

            return [
                'success' => true,
                'message' => 'Checkout dan pembayaran berhasil',
                'order' => $order->load('items.product', 'table', 'payments', 'discount'),
            ];

        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Create order untuk Midtrans payment (status: PENDING)
     */
    public function createCheckoutOrderForMidtrans(array $validated, ?int $outletId = null): array
    {
        $user = $this->currentUser();
        $outletId ??= $validated['outlet_id'] ?? null;

        if (!$outletId && !empty($validated['table_id'])) {
            $outletId = Table::find($validated['table_id'])?->outlet_id;
        }

        $outletId ??= $user->outlet_id;
        $outlet = Outlet::findOrFail($outletId);

        if (!$this->canAccessOutlet($outlet->id)) {
            throw new \Exception('Forbidden: Anda tidak memiliki akses ke Cabang ini.');
        }

        DB::beginTransaction();

        try {
            $table = Table::where('id', $validated['table_id'])
                ->where('outlet_id', $outlet->id)
                ->lockForUpdate()
                ->firstOrFail();

            $invoice = $this->generateInvoiceNumberWithRetry($outlet->id);

            // Resolve midtrans key dari owner outlet (multi-tenant), bukan dari
            // role user yang sedang checkout - konsisten dengan createPublicOrder().
            $midtransKeyUsed = $outlet->owner_id
                ? User::whereKey($outlet->owner_id)->value('midtrans_server_key')
                : null;

            $order = Order::create([
                'outlet_id' => $outlet->id,
                'user_id' => $user->id,
                'table_id' => $table->id,
                'customer_name' => $validated['customer_name'] ?? null,
                'invoice_number' => $invoice,
                'status' => Order::STATUS_PENDING, // PENDING karena menunggu pembayaran Midtrans
                'total_price' => 0,
                'midtrans_server_key_used' => $midtransKeyUsed,
                'payment_method' => $this->normalizePaymentMethod($validated['payment_method'] ?? null),
            ]);

            $this->createOrderItems($order, $validated['items'], $outlet);
            $this->handleAdjustments($order, $validated);
            $order->recalculateTotals($validated);

            $this->reserveTable($table);


            DB::commit();

            $broadcastOrder = $order->fresh()->load('items.product', 'table', 'discount');
            if (is_null($order->user_id) && $order->payment_method !== 'qris') {
              broadcast(new OrderCreated($broadcastOrder))->toOthers();
            }
            return [
                'success' => true,
                'message' => 'Order dibuat, silakan lanjut ke pembayaran Midtrans',
                'order' => $order->load('items.product', 'table', 'discount'),
            ];

        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function createPublicOrder(array $validated): array
    {
        $outlet = Outlet::findOrFail($validated['outlet_id']);

        DB::beginTransaction();

        try {
            $table = Table::where('id', $validated['table_id'])
                ->where('outlet_id', $outlet->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Resolve midtrans key dari owner outlet (multi-tenant)
            $ownerId = $outlet->owner_id;
            $midtransKeyUsed = $ownerId ? User::whereKey($ownerId)->value('midtrans_server_key') : null;

            $order = Order::create([
                'outlet_id' => $validated['outlet_id'],
                'table_id' => $validated['table_id'],
                'user_id' => null,
                'shift_id' => null,
                'invoice_number' => null,
                'payment_method' => $this->normalizePaymentMethod($validated['payment_method'] ?? null),
                'subtotal_price' => $validated['subtotal_price'] ?? 0,
                'discount_amount' => $validated['discount_amount'] ?? 0,
                'total_price' => $validated['total_price'] ?? 0,
                'customer_name' => $validated['customer_name'] ?? null,
                'status' => 'pending',
                'manual_discount_type' => $validated['manual_discount_type'] ?? null,
                'manual_discount_value' => $validated['manual_discount_value'] ?? 0,
                'discount_id' => $validated['discount_id'] ?? null,
                'tax_id' => $validated['tax_id'] ?? null,
                'tax_amount' => $validated['tax_amount'] ?? 0,
                'tax_breakdown' => isset($validated['tax_breakdown']) ? json_encode($validated['tax_breakdown']) : null,
                'midtrans_server_key_used' => $midtransKeyUsed,
            ]);


            $this->createOrderItems($order, $validated['items'], $outlet, true);
            $this->handleAdjustments($order, $validated);
            $order->recalculateTotals($validated);
            $this->reserveTable($table);

            // Pakai varian dengan retry (sama seperti jalur cash & midtrans).
            // Saat dua order QR publik dibuat bersamaan di hari yang sama dan
            // baris counter harian belum ada, firstOrCreate bisa memicu
            // "Duplicate entry" pada unique (outlet_id, date); retry menanganinya
            // alih-alih menggagalkan order secara fatal.
            $invoiceNumber = $this->generateInvoiceNumberWithRetry($outlet->id);

            // Update order dengan invoice number yang asli
            $order->update(['invoice_number' => $invoiceNumber]);

            $paymentUrl = null;

            DB::commit();

            $broadcastOrder = $order->fresh()->load('items.product', 'table');
            if ($order->payment_method !== 'qris') {
              broadcast(new OrderCreated($broadcastOrder))->toOthers();
            }

            return [
                'message' => 'Public order berhasil',
                'order' => $order->load('items.product'),
                'payment_url' => $paymentUrl,
            ];

        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function processPayments(Order $order, array $payments): array
    {
        $user = $this->currentUser();

        if (!$this->canAccessOrder($order)) {
            throw new \Exception('Forbidden');
        }

        if ($order->status === Order::STATUS_PAID || $order->status === Order::STATUS_CANCELLED) {
            throw new \Exception('Order cannot be paid');
        }

        DB::beginTransaction();

        try {
            $payableTotal = $order->payableTotal();
            $paymentSum = $order->payments()->selectRaw('SUM(amount_paid) as total_paid, SUM(change_amount) as total_change')->first();
            $alreadyPaid = ($paymentSum->total_paid ?? 0) - ($paymentSum->total_change ?? 0);
            $remaining = max(0, $payableTotal - $alreadyPaid);

            foreach ($payments as $paymentData) {
                if ($remaining <= 0) break;

                $amount = (int) $paymentData['amount_paid'];
                $applied = min($amount, $remaining);
                $change = max(0, $amount - $applied);

                $createdPayment = Payment::create([
                    'order_id' => $order->id,
                    'amount_paid' => $amount,
                    'change_amount' => $change,
                    'method' => $paymentData['method'],
                    'reference_no' => $paymentData['reference_no'] ?? null,
                    'paid_at' => now(),
                    'paid_by' => $user->id,
                ]);

                // Sync method payment terakhir ke orders.payment_method (agar Flutter tahu pembayarannya).
                $order->payment_method = $this->normalizePaymentMethod($createdPayment->method);
                $order->save();

                $remaining -= $applied;
            }

            $paymentSumFinal = $order->payments()->selectRaw('SUM(amount_paid) as total_paid, SUM(change_amount) as total_change')->first();
            $effectivePaid = ($paymentSumFinal->total_paid ?? 0) - ($paymentSumFinal->total_change ?? 0);
            $isFullyPaid = $effectivePaid >= $payableTotal;

            if ($isFullyPaid) {
                $order->update(['status' => Order::STATUS_PAID]);
                $this->storeHistoryTransaction($order);
                if ($order->table_id) {
                    $order->table->update(['status' => 'available']);
                }
            }

            DB::commit();

            if ($isFullyPaid) {
                $broadcastOrder = $order->fresh()->load('items.product', 'table');
                event(new \App\Events\PaymentPaid($broadcastOrder));
                broadcast(new OrderUpdated($broadcastOrder))->toOthers();
            }

            return [
                'is_paid' => $isFullyPaid,
                'remaining' => max(0, $payableTotal - $effectivePaid),
                'order' => $order->fresh()->load('items.product', 'payments'),
            ];

        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function syncHistoryTransaction(Order $order): void
    {
        $this->storeHistoryTransaction($order);
    }

    private function createOrderItems(Order $order, array $items, Outlet $outlet, bool $checkStock = true): void
    {
        $user = auth()->user();
        $userId = $user?->id;
        $invoiceNumber = $order->invoice_number;

        $sortedItems = collect($items)->sortBy('product_id')->values();

        $stockUpdates = [];
        $stockHistories = [];

        foreach ($sortedItems as $item) {
            $product = $outlet->products()->where('products.id', $item['product_id'])->wherePivot('is_active', true)->lockForUpdate()->firstOrFail();

            $stock = (int) $product->pivot->stock;
            $qty = (int) $item['qty'];

            if ($checkStock && $stock < $qty) {
                throw new \Exception("Stok {$product->name} tidak cukup (Sisa: {$stock})");
            }

            $price = (int) (
                $product->pivot->price ?? ($item['price'] ?? null) ?? $product->cost_price ?? 0
            );

            if ($price <= 0) {
                throw new \Exception("Harga {$product->name} belum diatur");
            }

            $stationId = $product->pivot->station_id ?? $product->station_id;

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'station_id' => $stationId,
                'qty' => $qty,
                'price' => $price,
                'total_price' => $price * $qty,
                'notes' => $item['notes'] ?? null,
            ]);

            if ($checkStock) {
                $newStock = $stock - $qty;
                $stockUpdates[$product->id] = $newStock;

                $stockHistories[] = [
                    'outlet_id' => $outlet->id,
                    'product_id' => $product->id,
                    'user_id' => $userId,
                    'type' => 'sale',
                    'quantity' => -$qty,
                    'final_stock' => $newStock,
                    'reference' => 'Order: ' . $invoiceNumber,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach ($stockUpdates as $productId => $newStock) {
            $outlet->products()->updateExistingPivot($productId, ['stock' => $newStock]);
        }

        if (!empty($stockHistories)) {
            StockHistory::insert($stockHistories);
        }
    }

    private function handleAdjustments(Order $order, array $data): void
    {
        $updates = [];
        $order->loadMissing('items.product');
        $subtotal = $order->items->sum('total_price');

        // Ambil discount_id tunggal hasil normalisasi di controller
        $discountId = $data['discount_id'] ?? null;
        $totalDiscountAmount = 0;
        $discount = null;

        // Daftar discount_ids bertumpuk (khusus scope produk/kategori). Kalau ada
        // lebih dari satu, controller sengaja TIDAK mengecilkannya jadi discount_id
        // tunggal supaya kita bisa hitung gabungannya di sini.
        $stackedDiscountIds = (!empty($data['discount_ids']) && is_array($data['discount_ids']))
            ? array_values(array_unique(array_map('intval', $data['discount_ids'])))
            : [];

        $orderOwnerId = Outlet::query()->whereKey($order->outlet_id)->value('owner_id');

        // JALUR 0: Beberapa diskon produk/kategori dipilih sekaligus (bertumpuk).
        // Tiap item hanya didiskon SEKALI (ambil diskon paling menguntungkan),
        // lalu nilai akhir disimpan sebagai discount_amount agar bertahan melewati
        // recalculateTotals (yang memakai $this->discount_amount saat discount_id null).
        if (count($stackedDiscountIds) > 1) {
            $totalDiscountAmount = $this->computeStackedDiscount($order, $stackedDiscountIds, (int) $subtotal, $orderOwnerId);

            // Increment used_count untuk semua diskon yang digunakan (stacked)
            if ($totalDiscountAmount > 0) {
                Discount::whereIn('id', $stackedDiscountIds)->increment('used_count');
            }

            $updates['discount_id'] = null;
            $updates['manual_discount_type'] = null;
            $updates['manual_discount_value'] = 0;

        // JALUR 1: Jika pelanggan memilih Master Diskon Global (Voucher) dari UI QR Menu
        } elseif ($discountId) {
            $discount = Discount::find($discountId);
            if (!$discount) {
                throw new \Exception("Diskon dengan ID {$discountId} tidak ditemukan di database.");
            }

            // Validasi diskon masih aktif
            if (!$discount->is_active) {
                throw new \Exception("Diskon {$discount->name} sudah tidak aktif.");
            }

            // Validasi periode aktif diskon
            $today = now()->format('Y-m-d');
            if ($discount->start_date > $today || $discount->end_date < $today) {
                throw new \Exception("Diskon {$discount->name} tidak sedang dalam periode aktif.");
            }

            // Validasi max_usage (batas pemakaian)
            if ($discount->max_usage > 0 && $discount->used_count >= $discount->max_usage) {
                throw new \Exception("Diskon {$discount->name} sudah mencapai batas pemakaian.");
            }

            // Pastikan diskon ini memang milik owner outlet tempat order dibuat.
            // Tanpa ini, discount_id milik tenant lain bisa diterapkan ke order
            // (defense kedua setelah filter di endpoint publik /public/discounts).
            $orderOwnerId = Outlet::query()->whereKey($order->outlet_id)->value('owner_id');
            if (!$orderOwnerId || (int) $discount->owner_id !== (int) $orderOwnerId) {
                throw new \Exception("Diskon tidak berlaku untuk outlet ini.");
            }

            // Validasi syarat minimal pembelian untuk SEMUA tipe scope
            if ($discount->min_purchase > 0 && $subtotal < $discount->min_purchase) {
                throw new \Exception("Minimum belanja Rp " . number_format($discount->min_purchase, 0, ',', '.') . " belum terpenuhi.");
            }

            // PERBAIKAN BUG: Amankan dari huruf besar/kecil & spasi di database
            $scope = strtolower(trim($discount->scope ?? 'global'));
            $type = strtolower(trim($discount->type ?? 'nominal'));

            // Dukung tipe 'global' maupun 'transaction', dan jangan tolak products/categories
            if (in_array($scope, ['global', 'transaction', 'all', ''])) {
                if ($type === 'percentage') {
                    // PERBAIKAN BUG: Gunakan (float) agar hitungan tidak jadi 0
                    $calc = $subtotal * ((float)$discount->value / 100);
                    if ($discount->max_discount && $calc > $discount->max_discount) {
                        $calc = $discount->max_discount;
                    }
                    $totalDiscountAmount = (int) $calc;
                } else {
                    $totalDiscountAmount = (int) min($discount->value, $subtotal);
                }
            } else {
                // Untuk scope products/categories, nilai presisi akan dihitung tuntas di Order::recalculateTotals()
                $totalDiscountAmount = 0;
            }

            // Increment used_count untuk single discount path
            $discount->increment('used_count');

            $updates['discount_id'] = $discount->id;
            $updates['manual_discount_type'] = null;
            $updates['manual_discount_value'] = 0;

        } elseif (!empty($data['manual_discount_type']) && isset($data['manual_discount_value'])) {
            $updates['manual_discount_type'] = $data['manual_discount_type'];
            $updates['manual_discount_value'] = (int) $data['manual_discount_value'];

            if ($data['manual_discount_type'] === 'percentage') {
                $totalDiscountAmount = (int) round($subtotal * ((float)$data['manual_discount_value'] / 100));
            } else {
                $totalDiscountAmount = (int) $data['manual_discount_value'];
            }
            $updates['discount_id'] = null;
        } elseif (!$discountId && empty($data['manual_discount_type'])) {
            // JALUR 3 (FALLBACK): Hanya jika TIDAK ada discount_id dan TIDAK ada manual_discount
            // Di sini sistem akan menghitung total akumulasi diskon produk bawaan item menu.
            $totalProductDiscount = 0;
            foreach ($order->items as $item) {
                if ($item->product && $item->product->is_promo) {
                    $totalProductDiscount += $item->product->discount_amount_per_item * $item->qty;
                }
            }

            if ($totalProductDiscount > 0) {
                $updates['manual_discount_type'] = 'nominal';
                $updates['manual_discount_value'] = $totalProductDiscount;
                $totalDiscountAmount = $totalProductDiscount;
                $updates['discount_id'] = null;
            }
        }

        // Amankan nilai akhir diskon agar masuk ke properti order sebelum update tunggal database
        $order->discount_amount = (int) min($subtotal, max(0, $totalDiscountAmount));
        $updates['discount_amount'] = $order->discount_amount;

        // ==========================================================================
        // LOGIKA HANDLING PAJAK (Bawaan asli kode Anda, dipertahankan agar tetap stabil)
        // ==========================================================================
        if (isset($data['tax_id'])) {
            // Pastikan pajak ini memang milik outlet tempat order dibuat -
            // defense kedua setelah filter di endpoint publik /public/taxes.
            $taxBelongsToOutlet = Tax::query()->whereKey($data['tax_id'])->where('outlet_id', $order->outlet_id)->exists();
            if ($taxBelongsToOutlet) {
                $updates['tax_id'] = $data['tax_id'];
            }
        } elseif (isset($data['tax_type']) && isset($data['tax_value'])) {
            $taxType = (string) $data['tax_type'];
            $taxValue = (int) $data['tax_value'];
            $matchedTax = Tax::query()->where('type', $taxType)->where('active', true)->where('outlet_id', $order->outlet_id)->get()->first(function (Tax $tax) use ($taxValue) {
                $expectedValue = $tax->type === 'percentage' ? (int) round(((float) $tax->rate) * 100) : (int) round((float) $tax->rate);
                return $expectedValue === $taxValue;
            });
            if ($matchedTax) $updates['tax_id'] = $matchedTax->id;
        }

        if (array_key_exists('tax_breakdown', $data)) {
            $updates['tax_breakdown'] = $data['tax_breakdown'];
        }

        if (array_key_exists('tax_amount', $data)) {
            $updates['tax_amount'] = max(0, (int) $data['tax_amount']);
        }

        if (!empty($updates)) {
            $order->update($updates);
        }
    }

    /**
     * Hitung total potongan untuk beberapa diskon produk/kategori yang dipilih
     * sekaligus (bertumpuk). Aturan: tiap item hanya didiskon SEKALI memakai diskon
     * yang paling menguntungkan pelanggan. Logika ini SENGAJA dibuat identik dengan
     * perhitungan di frontend (posqr stores/cart.js) supaya total yang ditampilkan
     * ke pelanggan sama persis dengan yang ditagih lewat Midtrans.
     */
    private function computeStackedDiscount(Order $order, array $discountIds, int $subtotal, $ownerId): int
    {
        $today = now()->format('Y-m-d');

        $discounts = Discount::whereIn('id', $discountIds)
            ->where('is_active', true)
            ->get()
            ->filter(fn($d) => $ownerId && (int) $d->owner_id === (int) $ownerId)
            ->filter(function ($d) use ($subtotal) {
                $min = (int) ($d->min_purchase ?? 0);
                return $min === 0 || $subtotal >= $min;
            })
            // Filter hanya diskon yang sedang dalam periode aktif
            ->filter(fn($d) => $d->start_date <= $today && $d->end_date >= $today)
            // Filter hanya diskon yang belum habis kuota pemakaian
            ->filter(fn($d) => $d->max_usage <= 0 || $d->used_count < $d->max_usage)
            ->filter(fn($d) => in_array(strtolower(trim($d->scope ?? '')), ['products', 'categories']))
            ->values();

        if ($discounts->isEmpty()) {
            return 0;
        }

        // Per item: pilih satu diskon dengan potongan terbesar, lalu akumulasi per diskon
        // (supaya cap max_discount bisa diterapkan per diskon).
        $perDiscountSum = [];

        foreach ($order->items as $item) {
            $line = (int) $item->price * (int) $item->qty;
            $qty = (int) $item->qty;
            $catId = $item->product->category_id ?? null;

            $bestVal = 0.0;
            $bestId = null;

            foreach ($discounts as $d) {
                $scope = strtolower(trim($d->scope));
                $matches = false;

                if ($scope === 'products') {
                    $ids = is_array($d->product_ids) ? array_map('intval', $d->product_ids) : [];
                    $matches = in_array((int) $item->product_id, $ids, true);
                } elseif ($scope === 'categories') {
                    $cats = is_array($d->category_ids) ? array_map('intval', $d->category_ids) : [];
                    $matches = $catId !== null && in_array((int) $catId, $cats, true);
                }

                if (!$matches) {
                    continue;
                }

                $type = strtolower(trim($d->type ?? 'nominal'));
                $val = $type === 'percentage'
                    ? $line * ((float) $d->value / 100)
                    : (float) $d->value * $qty;

                if ($val > $bestVal) {
                    $bestVal = $val;
                    $bestId = $d->id;
                }
            }

            if ($bestId !== null && $bestVal > 0) {
                $perDiscountSum[$bestId] = ($perDiscountSum[$bestId] ?? 0) + $bestVal;
            }
        }

        $total = 0.0;
        foreach ($perDiscountSum as $id => $sum) {
            $d = $discounts->firstWhere('id', $id);
            $type = strtolower(trim($d->type ?? 'nominal'));
            if ($type === 'percentage') {
                $max = (int) ($d->max_discount ?? 0);
                if ($max > 0 && $sum > $max) {
                    $sum = $max;
                }
            }
            $total += $sum;
        }

        return (int) min($subtotal, round($total));
    }

    private function createPayment(Order $order, int $amountPaid, string $method): Payment
    {
        $change = max(0, $amountPaid - $order->payableTotal());
        $user = $this->currentUser();

        return Payment::create([
            'order_id' => $order->id,
            'amount_paid' => $amountPaid,
            'change_amount' => $change,
            'method' => strtolower($method),
            'paid_at' => now(),
            'paid_by' => $user->id,
        ]);
    }

    private function storeHistoryTransaction(Order $order): void
    {
        $order->load(['payments', 'items.product']);
        $lastPayment = $order->payments->sortByDesc('id')->first();
        $methods = $order->payments->pluck('method')->unique()->values()->all();

        $paymentMethod = count($methods) === 1 ? $methods[0] : (count($methods) > 1 ? 'split' : $order->payment_method);

        $paidAmount = $order->payments->sum(fn($p) => $p->amount_paid - $p->change_amount);

        if ($paidAmount == 0 && $order->status === Order::STATUS_PAID) {
            $paidAmount = $order->total_price;
        }

        $changeAmount = $order->payments->sum('change_amount');
        $orderItemsSummary = $order->items->map(function ($item) {
            return [
                'product_id' => (int) $item->product_id,
                'product_name' => $item->product?->name,
                'qty' => (int) $item->qty,
                'price' => (int) $item->price,
                'total_price' => (int) $item->total_price,
                'cancelled_qty' => (int) ($item->cancelled_qty ?? 0),
                'notes' => $item->notes,
            ];
        })->values()->all();

        HistoryTransaction::updateOrCreate(
            ['order_id' => $order->id],
            [
                'outlet_id' => $order->outlet_id,
                'payment_id' => $lastPayment?->id,
                'invoice_number' => $order->invoice_number,
                'customer_name' => $order->customer_name,
                'subtotal_price' => $order->subtotal_price,
                'discount_amount' => $order->discount_amount,
                'tax_amount' => $order->tax_amount,
                'total_price' => $order->total_price,
                'paid_amount' => $paidAmount,
                'change_amount' => $changeAmount,
                'payment_method' => $paymentMethod,
                'paid_at' => $lastPayment?->paid_at ?? $order->updated_at ?? now(),
                'cashier_id' => $lastPayment?->paid_by ?? $order->user_id,
                'status' => Order::STATUS_PAID,
                'metadata' => [
                    'payments_count' => $order->payments->count(),
                    'methods' => $methods,
                    'items_count' => $order->items->count(),
                ],
                'order_items_summary' => $orderItemsSummary,
            ]
        );
    }

    private function generateInvoiceNumber(int $outletId): string
    {
        $date = now()->format('Ymd');

        $counter = InvoiceCounter::lockForUpdate()
            ->firstOrCreate(
                [
                    'outlet_id' => $outletId,
                    'date' => $date
                ],
                [
                    'last_number' => 0
                ]
            );

        $counter->increment('last_number');

        $number = $counter->last_number;

        if ($number > 9999) {
            throw new \Exception('Invoice limit harian tercapai');
        }

        $sequence = str_pad($number, 4, '0', STR_PAD_LEFT);

        return "INV-{$date}-{$sequence}";
    }

    private function generateInvoiceNumberWithRetry(int $outletId): string
    {
        $maxRetry = 5;
        $attempt = 0;
        do {
            try {
                return $this->generateInvoiceNumber($outletId);
            } catch (\Illuminate\Database\QueryException $e) {
                if (str_contains($e->getMessage(), 'Duplicate entry')) {
                    $attempt++;
                    usleep(100000);
                } else {
                    throw $e;
                }
            }
        } while ($attempt < $maxRetry);
        throw new \Exception('Gagal generate invoice unik, coba lagi');
    }

    private function canAccessOutlet(int $outletId): bool
    {
        $user = $this->currentUser();
        if ($user->role === 'developer') return true;
        if ($user->role === 'manager') return Outlet::where('id', $outletId)->where('owner_id', $user->id)->exists();
        return (int) $user->outlet_id === $outletId;
    }

    private function canAccessOrder(Order $order): bool
    {
        return $this->canAccessOutlet($order->outlet_id);
    }

    private function currentUser(): User
    {
        $user = auth()->user();
        if (!$user instanceof User) throw new \RuntimeException('Unauthenticated');
        return $user;
    }

    private function normalizePaymentMethod(?string $method): ?string
    {
        if (!$method) return null;

        $m = strtolower(trim($method));

        // Kelompokkan 'midtrans' ke 'qris' supaya tidak terlalu universal
        if (in_array($m, ['midtrans', 'qris', 'other_qris', 'gopay', 'shopeepay'])) {
            return 'qris';
        }

        if (in_array($m, ['cash', 'tunai'])) {
            return 'cash';
        }

        return $m;
    }

    private function reserveTable(Table $table, int $minutes = 20): void
    {
        $table->update([
            'status' => 'reserved',
            'reserved_until' => now()->addMinutes($minutes),
        ]);
    }
}
