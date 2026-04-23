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
            $order = Order::create([
                'outlet_id' => $outlet->id,
                'user_id' => $user->id,
                'table_id' => $table->id,
                'customer_name' => $validated['customer_name'] ?? null,
                'invoice_number' => $this->generateInvoiceNumber($outlet->id),
                'status' => Order::STATUS_PAID,
                'total_price' => 0,
            ]);

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

    public function createPublicOrder(array $validated): array
    {
        $outlet = Outlet::findOrFail($validated['outlet_id']);
        $table = Table::where('id', $validated['table_id'])->where('outlet_id', $outlet->id)->firstOrFail();

        DB::beginTransaction();

        try {
            $order = Order::create([
                'outlet_id' => $validated['outlet_id'],
                'table_id' => $table->id,
                'customer_name' => $validated['customer_name'],
                'status' => Order::STATUS_PENDING,
            ]);

            $this->createOrderItems($order, $validated['items'], $outlet, false);
            $this->handleAdjustments($order, $validated);
            $order->recalculateTotals($validated);

            $order->update(['invoice_number' => 'INV-' . strtoupper(uniqid())]);

            DB::commit();

            return [
                'message' => 'Public order berhasil',
                'order' => $order->load('items.product'),
            ];

        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function processPayments(Order $order, array $payments): array
    {
        $user = $this->currentUser();

        if (! $this->canAccessOrder($order)) {
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

                Payment::create([
                    'order_id' => $order->id,
                    'amount_paid' => $amount,
                    'change_amount' => $change,
                    'method' => $paymentData['method'],
                    'reference_no' => $paymentData['reference_no'] ?? null,
                    'paid_at' => now(),
                    'paid_by' => $user->id,
                ]);

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
        $user = $this->currentUser();

        foreach ($items as $item) {
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
                    'user_id' => $user->id,
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

        if (array_key_exists('discount_id', $data)) {
            $updates['discount_id'] = $data['discount_id'] ? (int) $data['discount_id'] : null;
        }

        if (isset($data['manual_discount_type'])) {
            $updates['manual_discount_type'] = $data['manual_discount_type'];
        } elseif (isset($data['discount_type'])) {
            $updates['manual_discount_type'] = $data['discount_type'];
        }

        if (isset($data['manual_discount_value'])) {
            $updates['manual_discount_value'] = (int) $data['manual_discount_value'];
        } elseif (isset($data['discount_value'])) {
            $updates['manual_discount_value'] = (int) $data['discount_value'];
        }

        $shouldResolvePromoDiscount = isset($updates['discount_id'])
            && !isset($updates['manual_discount_type'])
            && !isset($updates['manual_discount_value']);

        if ($shouldResolvePromoDiscount && $updates['discount_id']) {
            $discount = Discount::query()
                ->whereKey($updates['discount_id'])
                ->where('is_active', true)
                ->first();

            if ($discount) {
                $order->loadMissing('items.product'); // Load produk untuk ngecek kategori
                $subtotal = $order->items->sum('total_price');

                if ($discount->min_purchase > 0 && $subtotal < $discount->min_purchase) {
                    throw new \Exception("Minimum belanja Rp " . number_format($discount->min_purchase, 0, ',', '.') . " belum terpenuhi.");
                }

                $discountValue = (int) $discount->value;
                $eligibleTotal = 0;

                // PERBAIKAN: Hitung total khusus item yang kena diskon (Multiple Products atau Categories)
                if ($discount->scope === 'products' && !empty($discount->product_ids)) {
                    $eligibleTotal = $order->items->whereIn('product_id', $discount->product_ids)->sum('total_price');
                } elseif ($discount->scope === 'categories' && !empty($discount->category_ids)) {
                    $eligibleTotal = $order->items->filter(function ($item) use ($discount) {
                        return in_array($item->product->category_id, $discount->category_ids);
                    })->sum('total_price');
                }

                if ($discount->scope !== 'global') {
                    if ($eligibleTotal > 0) {
                        if ($discount->type === 'percentage') {
                            $calc = $eligibleTotal * ($discountValue / 100);
                            if ($discount->max_discount && $calc > $discount->max_discount) {
                                $calc = $discount->max_discount;
                            }
                            $updates['manual_discount_type'] = 'nominal';
                            $updates['manual_discount_value'] = (int) $calc;
                        } else {
                            $updates['manual_discount_type'] = 'nominal';
                            // Diskon nominal tidak boleh melebihi harga produk yg didiskon itu sendiri
                            $updates['manual_discount_value'] = min($discountValue, $eligibleTotal);
                        }
                    } else {
                        // Jika produk/kategori yg didiskon tidak ada di keranjang
                        $updates['manual_discount_type'] = 'nominal';
                        $updates['manual_discount_value'] = 0;
                    }
                } else {
                    // Global Scope
                    if ($discount->type === 'percentage') {
                        $calc = $subtotal * ($discountValue / 100);
                        if ($discount->max_discount && $calc > $discount->max_discount) {
                            $updates['manual_discount_type'] = 'nominal';
                            $updates['manual_discount_value'] = (int) $discount->max_discount;
                        } else {
                            $updates['manual_discount_type'] = 'percentage';
                            $updates['manual_discount_value'] = $discountValue;
                        }
                    } else {
                        $updates['manual_discount_type'] = 'nominal';
                        $updates['manual_discount_value'] = $discountValue;
                    }
                }
            }
        }

        // --- Tax Logic (Sama seperti aslinya) ---
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
        return DB::transaction(function () {

            $now = now();
            $prefix = 'INV-' . $now->format('YmdHis');

            // Lock biar tidak bentrok saat high traffic
            $last = Order::where('invoice_number', 'like', $prefix . '%')
                ->lockForUpdate()
                ->orderByDesc('invoice_number')
                ->value('invoice_number');

            if ($last) {
                // Ambil angka terakhir
                $lastNumber = (int) substr($last, -4);
                $nextNumber = $lastNumber + 1;
            } else {
                $nextNumber = 1;
            }

            // Maksimal 9999 (opsional safety)
            if ($nextNumber > 9999) {
                throw new \Exception('Invoice limit reached for this second');
            }

            $sequence = str_pad($nextNumber, 4, '0', STR_PAD_LEFT);

            return $prefix . '-' . $sequence;
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
}
