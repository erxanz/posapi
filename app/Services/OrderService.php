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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Models\User;

class OrderService
{
    public function __construct(
        private User $user
    ) {}

    /**
     * Create checkout order with payment
     */
    public function createCheckoutOrder(array $validated, ?int $outletId = null): array
    {
        $outletId ??= $this->user->outlet_id;
        $outlet = Outlet::findOrFail($outletId);

        if (!$this->canAccessOutlet($outlet->id)) {
            throw new \Exception('Forbidden');
        }

        $table = Table::where('id', $validated['table_id'])
            ->where('outlet_id', $outlet->id)
            ->firstOrFail();

        DB::beginTransaction();

        try {
            $order = Order::create([
                'outlet_id' => $outlet->id,
                'user_id' => $this->user->id,
                'table_id' => $table->id,
                'customer_name' => $validated['customer_name'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'invoice_number' => $this->generateInvoiceNumber($outlet->id),
                'status' => Order::STATUS_PAID,
                'total_price' => 0, // Will be recalculated
            ]);

            $this->createOrderItems($order, $validated['items'], $outlet);
            $this->handleAdjustments($order, $validated); // tax_id etc if provided

            $order->recalculateTotals();

            $payment = $this->createPayment($order, $validated['amount_paid'], $validated['payment_method']);
            $this->storeHistoryTransaction($order);
            $table->update(['status' => 'available']);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Checkout berhasil',
                'order' => $order->load('items.product', 'table', 'payments'),
            ];

        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Create public order (QR)
     */
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

            $this->createOrderItems($order, $validated['items'], $outlet, checkStock: false);
            $this->handleAdjustments($order, $validated);
            $order->recalculateTotals();

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

    /**
     * Process payments (supports split)
     */
    public function processPayments(Order $order, array $payments): array
    {
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
                    'paid_by' => $this->user->id,
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

    // Private helpers...
    private function createOrderItems(Order $order, array $items, Outlet $outlet, bool $checkStock = true): void
    {
        foreach ($items as $item) {
            $product = $outlet->products()->where('products.id', $item['product_id'])->wherePivot('is_active', true)->lockForUpdate()->firstOrFail();

            $stock = (int) $product->pivot->stock;
            $qty = (int) $item['qty'];

            if ($checkStock && $stock < $qty) {
                throw new \Exception("Stok {$product->name} tidak cukup (Sisa: {$stock})");
            }

            $price = (int) $product->pivot->price;
            $stationId = $product->pivot->station_id ?? $product->station_id;

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'station_id' => $stationId,
                'qty' => $qty,
                'price' => $price,
                'total_price' => $price * $qty,
            ]);

            if ($checkStock) {
                $newStock = $stock - $qty;
                $outlet->products()->updateExistingPivot($product->id, ['stock' => $newStock]);

                StockHistory::create([
                    'outlet_id' => $outlet->id,
                    'product_id' => $product->id,
                    'user_id' => $this->user->id,
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
        // Set manual_discount_*, tax_id from data if provided
        $updates = [];
        if (isset($data['manual_discount_type'])) $updates['manual_discount_type'] = $data['manual_discount_type'];
        if (isset($data['manual_discount_value'])) $updates['manual_discount_value'] = $data['manual_discount_value'];
        if (isset($data['tax_id'])) $updates['tax_id'] = $data['tax_id'];

        if (!empty($updates)) {
            $order->update($updates);
        }
    }

    private function createPayment(Order $order, int $amountPaid, string $method): Payment
    {
        $change = max(0, $amountPaid - $order->total_price);
        return Payment::create([
            'order_id' => $order->id,
            'amount_paid' => $amountPaid,
            'change_amount' => $change,
            'method' => strtolower($method),
            'paid_at' => now(),
            'paid_by' => $this->user->id,
        ]);
    }

    private function storeHistoryTransaction(Order $order): void
    {
        $order->load(['payments', 'items']);
        // Implementation same as original storeHistoryTransaction...
        // (copy logic here, using model constants)
        $lastPayment = $order->payments->sortByDesc('id')->first();
        $methods = $order->payments->pluck('method')->unique()->values()->all();
        $paymentMethod = count($methods) === 1 ? $methods[0] : (count($methods) > 1 ? 'split' : null);
        $paidAmount = $order->payments->sum(fn($p) => $p->amount_paid - $p->change_amount);
        $changeAmount = $order->payments->sum('change_amount');

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
            ]
        );
    }

    private function generateInvoiceNumber(int $outletId): string
    {
        return 'INV-' . str_pad((string) $outletId, 2, '0', STR_PAD_LEFT) . '-' . now()->format('YmdHis') . '-' . strtoupper(substr(uniqid(), -4));
    }

    private function canAccessOutlet(int $outletId): bool
    {
        if ($this->user->role === 'developer') return true;
        return (int) $this->user->outlet_id === $outletId;
    }

    private function canAccessOrder(Order $order): bool
    {
        return $this->canAccessOutlet($order->outlet_id);
    }
}

