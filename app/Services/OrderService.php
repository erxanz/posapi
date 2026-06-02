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

            // RETRY UNTUK INVOICE DUPLICATE
            $maxRetry = 5;
            $attempt = 0;

            do {
                try {

                    $invoice = $this->generateInvoiceNumber($outlet->id);

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


                    break; // sukses → keluar loop

                } catch (\Illuminate\Database\QueryException $e) {

                    if (str_contains($e->getMessage(), 'Duplicate entry')) {
                        $attempt++;
                        usleep(100000); // delay 0.1 detik
                    } else {
                        throw $e;
                    }

                }

            } while ($attempt < $maxRetry);

            if (!isset($order)) {
                throw new \Exception('Gagal generate invoice unik, coba lagi');
            }

            // ===============================
            // LANJUT PROSES NORMAL
            // ===============================

            $this->createOrderItems($order, $validated['items'], $outlet);
            $this->handleAdjustments($order, $validated);
            $order->recalculateTotals($validated);

            $amountPaid = (int) $validated['amount_paid'];
            if ($amountPaid < (int) $order->total_price) {
                throw new \Exception('Nominal bayar kurang dari total tagihan');
            }

            $this->createPayment($order, $amountPaid, $validated['payment_method']);
            $this->storeHistoryTransaction($order);
            $table->update(['status' => 'available']);

            DB::commit();

            $broadcastOrder = $order->fresh()->load('items.product', 'table');
            broadcast(new OrderCreated($broadcastOrder))->toOthers();

            return [
                'success' => true,
                'message' => 'Checkout dan pembayaran berhasil',
                'order' => $order->load('items.product', 'table', 'payments'),
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

            $maxRetry = 5;
            $attempt = 0;

            do {
                try {
                    $invoice = $this->generateInvoiceNumber($outlet->id);

                    $midtransKeyUsed = $user->role === 'manager'
                        ? ($user->midtrans_server_key ?: null)
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


                    break;

                } catch (\Illuminate\Database\QueryException $e) {
                    if (str_contains($e->getMessage(), 'Duplicate entry')) {
                        $attempt++;
                        usleep(100000);
                    } else {
                        throw $e;
                    }
                }

            } while ($attempt < $maxRetry);

            if (!isset($order)) {
                throw new \Exception('Gagal generate invoice unik, coba lagi');
            }

            $this->createOrderItems($order, $validated['items'], $outlet);
            $this->handleAdjustments($order, $validated);
            $order->recalculateTotals($validated);

            $this->reserveTable($table);

            // Jangan buat payment di sini, tunggu webhook dari Midtrans

            DB::commit();

            $broadcastOrder = $order->fresh()->load('items.product', 'table');
            broadcast(new OrderCreated($broadcastOrder))->toOthers();

            return [
                'success' => true,
                'message' => 'Order dibuat, silakan lanjut ke pembayaran Midtrans',
                'order' => $order->load('items.product', 'table'),
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
                'discount_type' => $validated['discount_type'] ?? null,
                'discount_value' => $validated['discount_value'] ?? 0,
                'tax_id' => $validated['tax_id'] ?? null,
                'tax_type' => $validated['tax_type'] ?? null,
                'tax_value' => $validated['tax_value'] ?? 0,
                'tax_amount' => $validated['tax_amount'] ?? 0,
                'tax_breakdown' => isset($validated['tax_breakdown']) ? json_encode($validated['tax_breakdown']) : null,
                'midtrans_server_key_used' => $midtransKeyUsed,
            ]);


            $this->createOrderItems($order, $validated['items'], $outlet, true);
            $this->handleAdjustments($order, $validated);
            $order->recalculateTotals($validated);
            $this->reserveTable($table);

            // --- PERBAIKAN DI SINI ---
            // Panggil fungsi yang menggunakan lockForUpdate tadi
            $invoiceNumber = $this->generateInvoiceNumber($outlet->id);

            // Update order dengan invoice number yang asli
            $order->update(['invoice_number' => $invoiceNumber]);
            // -------------------------

            $paymentUrl = null;
            if (isset($validated['payment_method']) && $validated['payment_method'] === 'midtrans') {
                //
            }

            DB::commit();

            $broadcastOrder = $order->fresh()->load('items.product', 'table');
            broadcast(new OrderCreated($broadcastOrder))->toOthers();

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
            $alreadyPaid = $order->payments()->sum('amount_paid') - $order->payments()->sum('change_amount');
            $remaining = max(0, $order->total_price - $alreadyPaid);

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

            $effectivePaid = $order->payments()->sum('amount_paid') - $order->payments()->sum('change_amount');
            $isFullyPaid = $effectivePaid >= $order->total_price;

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
                'remaining' => max(0, $order->total_price - $effectivePaid),
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

        foreach (collect($items)->sortBy('product_id')->values() as $item) {
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
                $outlet->products()->updateExistingPivot($product->id, ['stock' => $newStock]);

                StockHistory::create([
                    'outlet_id' => $outlet->id,
                    'product_id' => $product->id,
                    'user_id' => $user?->id,
                    'type' => 'sale',
                    'quantity' => -$qty,
                    'final_stock' => $newStock,
                    'reference' => 'Order: ' . $order->invoice_number,
                ]);
            }
        }
    }

private function handleAdjustments(Order $order, array $data): void
{
    $updates = [];
    $order->loadMissing('items.product');
    $subtotal = $order->items->sum('total_price');

    // 1. Ambil discount_id tunggal (antisipasi jika frontend mengirim array discount_ids)
    $discountId = null;
    if (!empty($data['discount_id'])) {
        $discountId = $data['discount_id'];
    } elseif (!empty($data['discount_ids']) && is_array($data['discount_ids'])) {
        $discountId = collect($data['discount_ids'])->first();
    }

    $totalDiscountAmount = 0;

    // 2. LOGIKA MEMBACA MASTER DISKON GLOBAL
    if ($discountId) {
        $discount = Discount::find($discountId);
        if ($discount && $discount->scope === 'global') {
            // Validasi syarat minimum pembelian
            if ($discount->min_purchase > 0 && $subtotal < $discount->min_purchase) {
                throw new \Exception("Minimum belanja Rp " . number_format($discount->min_purchase, 0, ',', '.') . " belum terpenuhi.");
            }

            if ($discount->type === 'percentage') {
                $calc = $subtotal * ((int)$discount->value / 100);
                if ($discount->max_discount && $calc > $discount->max_discount) {
                    $calc = $discount->max_discount;
                }
                $totalDiscountAmount = (int) $calc;
            } else {
                $totalDiscountAmount = min((int)$discount->value, $subtotal);
            }

            $updates['discount_id'] = $discount->id;
            $updates['manual_discount_type'] = null;
            $updates['manual_discount_value'] = 0;
        }
    } 
    // 3. LOGIKA MEMBACA DISKON MANUAL (Jika kasir input diskon langsung tanpa voucher)
    elseif (!empty($data['manual_discount_type']) && isset($data['manual_discount_value'])) {
        $updates['manual_discount_type'] = $data['manual_discount_type'];
        $updates['manual_discount_value'] = (int) $data['manual_discount_value'];

        if ($data['manual_discount_type'] === 'percentage') {
            $totalDiscountAmount = (int) round($subtotal * ((int)$data['manual_discount_value'] / 100));
        } else {
            $totalDiscountAmount = (int) $data['manual_discount_value'];
        }
        $updates['discount_id'] = null;
    } 
    // 4. FALLBACK: Jika tidak ada diskon global baru, tapi ada diskon produk bawaan menu
    else {
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

    // Amankan agar nilai diskon tidak minus atau melebihi subtotal
    $order->discount_amount = min($subtotal, max(0, $totalDiscountAmount));
    $updates['discount_amount'] = $order->discount_amount;

    // 5. HANDLING PAJAK (Bawaan Kode Anda yang sudah aman)
    if (isset($data['tax_id'])) {
        $updates['tax_id'] = $data['tax_id'];
    } elseif (isset($data['tax_type']) && isset($data['tax_value'])) {
        $taxType = (string) $data['tax_type'];
        $taxValue = (int) $data['tax_value'];
        $matchedTax = Tax::query()->where('type', $taxType)->where('active', true)->get()->first(function (Tax $tax) use ($taxValue) {
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

    private function createPayment(Order $order, int $amountPaid, string $method): Payment
    {
        $change = max(0, $amountPaid - $order->total_price);
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
        $paymentMethod = count($methods) === 1 ? $methods[0] : (count($methods) > 1 ? 'split' : null);
        $paidAmount = $order->payments->sum(fn($p) => $p->amount_paid - $p->change_amount);
        $changeAmount = $order->payments->sum('change_amount');
        $orderItemsSummary = $order->items->map(function ($item) {
            return [
                'product_id' => (int) $item->product_id,
                'product_name' => $item->product?->name,
                'qty' => (int) $item->qty,
                'price' => (int) $item->price,
                'total_price' => (int) $item->total_price,
                'cancelled_qty' => (int) ($item->cancelled_qty ?? 0),
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
                'paid_at' => $lastPayment?->paid_at ?? now(),
                'cashier_id' => $lastPayment?->paid_by,
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
        return DB::transaction(function () use ($outletId) {

            $date = now()->format('Ymd');

            // ambil / buat row khusus outlet + tanggal
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

            // increment AMAN (tidak bisa bentrok)
            $counter->increment('last_number');

            $number = $counter->last_number;

            if ($number > 9999) {
                throw new \Exception('Invoice limit harian tercapai');
            }

            $sequence = str_pad($number, 4, '0', STR_PAD_LEFT);

            return "INV-{$date}-{$sequence}";
        });
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

        if (in_array($m, ['qris', 'midtrans'])) {
            return 'qris';
        }

        if (in_array($m, ['card', 'credit_card'])) {
            return 'card';
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

