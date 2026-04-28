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
use App\Models\User;
use App\Models\InvoiceCounter;
use Illuminate\Support\Facades\DB;

class OrderService
{
    /*
    |--------------------------------------------------------------------------
    | CHECKOUT CASH / MANUAL PAYMENT
    |--------------------------------------------------------------------------
    */
    public function createCheckoutOrder(array $validated, ?int $outletId = null): array
    {
        return $this->createOrder($validated, false, $outletId);
    }

    /*
    |--------------------------------------------------------------------------
    | CHECKOUT MIDTRANS
    |--------------------------------------------------------------------------
    */
    public function createCheckoutOrderForMidtrans(array $validated, ?int $outletId = null): array
    {
        return $this->createOrder($validated, true, $outletId);
    }

    /*
    |--------------------------------------------------------------------------
    | MAIN ORDER CREATOR
    |--------------------------------------------------------------------------
    */
    private function createOrder(array $validated, bool $isMidtrans = false, ?int $outletId = null): array
    {
        $user = $this->currentUser();

        $outletId ??= $validated['outlet_id'] ?? null;

        if (!$outletId && !empty($validated['table_id'])) {
            $outletId = Table::find($validated['table_id'])?->outlet_id;
        }

        $outletId ??= $user->outlet_id;

        $outlet = Outlet::findOrFail($outletId);

        if (!$this->canAccessOutlet($outlet->id)) {
            throw new \Exception('Tidak memiliki akses outlet');
        }

        $table = Table::where('id', $validated['table_id'])
            ->where('outlet_id', $outlet->id)
            ->firstOrFail();

        DB::beginTransaction();

        try {
            $invoice = $this->generateInvoiceNumber($outlet->id);

            $order = Order::create([
                'outlet_id'      => $outlet->id,
                'user_id'        => $user->id,
                'table_id'       => $table->id,
                'customer_name'  => $validated['customer_name'] ?? null,
                'invoice_number' => $invoice,
                'status'         => $isMidtrans
                    ? Order::STATUS_PENDING
                    : Order::STATUS_PAID,
                'total_price'    => 0,
            ]);

            $this->createOrderItems($order, $validated['items'], $outlet);

            $this->handleAdjustments($order, $validated);

            $order->recalculateTotals($validated);

            if (!$isMidtrans) {
                $amountPaid = (int) $validated['amount_paid'];

                if ($amountPaid < $order->total_price) {
                    throw new \Exception('Nominal bayar kurang');
                }

                $this->createPayment(
                    $order,
                    $amountPaid,
                    $validated['payment_method']
                );

                $this->storeHistoryTransaction($order);

                $table->update([
                    'status' => 'available'
                ]);
            }

            DB::commit();

            return [
                'success' => true,
                'message' => $isMidtrans
                    ? 'Order dibuat, lanjut pembayaran'
                    : 'Checkout berhasil',
                'order' => $order->load(
                    'items.product',
                    'table',
                    'payments'
                )
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PAYMENT SPLIT / MANUAL
    |--------------------------------------------------------------------------
    */
    public function processPayments(Order $order, array $payments): array
    {
        if (!$this->canAccessOrder($order)) {
            throw new \Exception('Forbidden');
        }

        DB::beginTransaction();

        try {
            $order = Order::lockForUpdate()->findOrFail($order->id);

            if (
                $order->status === Order::STATUS_PAID ||
                $order->status === Order::STATUS_CANCELLED
            ) {
                throw new \Exception('Order tidak bisa dibayar');
            }

            $user = $this->currentUser();

            $alreadyPaid =
                $order->payments()->sum('amount_paid') -
                $order->payments()->sum('change_amount');

            $remaining = $order->total_price - $alreadyPaid;

            foreach ($payments as $pay) {
                if ($remaining <= 0) {
                    break;
                }

                $amount = (int) $pay['amount_paid'];

                $applied = min($amount, $remaining);

                $change = max(0, $amount - $applied);

                Payment::create([
                    'order_id'      => $order->id,
                    'amount_paid'   => $amount,
                    'change_amount' => $change,
                    'method'        => strtolower($pay['method']),
                    'reference_no'  => $pay['reference_no'] ?? null,
                    'paid_at'       => now(),
                    'paid_by'       => $user->id,
                ]);

                $remaining -= $applied;
            }

            $effectivePaid =
                $order->payments()->sum('amount_paid') -
                $order->payments()->sum('change_amount');

            $isPaid = $effectivePaid >= $order->total_price;

            if ($isPaid) {
                $order->update([
                    'status' => Order::STATUS_PAID
                ]);

                if ($order->table_id) {
                    $order->table()->update([
                        'status' => 'available'
                    ]);
                }

                $this->storeHistoryTransaction($order);
            }

            DB::commit();

            return [
                'is_paid'   => $isPaid,
                'remaining' => max(
                    0,
                    $order->total_price - $effectivePaid
                ),
                'order' => $order->fresh()->load(
                    'items.product',
                    'payments'
                ),
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | MIDTRANS SUCCESS CALLBACK
    |--------------------------------------------------------------------------
    */
    public function markAsPaidByGateway(
        Order $order,
        int $amount,
        string $trxId
    ): void {
        DB::beginTransaction();

        try {
            $order = Order::lockForUpdate()->findOrFail($order->id);

            if ($order->status === Order::STATUS_PAID) {
                DB::commit();
                return;
            }

            $alreadyExists = Payment::where(
                'reference_no',
                $trxId
            )->exists();

            if (!$alreadyExists) {
                Payment::create([
                    'order_id'      => $order->id,
                    'amount_paid'   => $amount,
                    'change_amount' => 0,
                    'method'        => 'midtrans',
                    'reference_no'  => $trxId,
                    'paid_at'       => now(),
                    'paid_by'       => null,
                ]);
            }

            $order->update([
                'status' => Order::STATUS_PAID
            ]);

            if ($order->table_id) {
                $order->table()->update([
                    'status' => 'available'
                ]);
            }

            $this->storeHistoryTransaction($order);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | MIDTRANS CANCEL / EXPIRE
    |--------------------------------------------------------------------------
    */
    public function cancelGatewayOrder(Order $order): void
    {
        DB::beginTransaction();

        try {
            $order = Order::lockForUpdate()->findOrFail($order->id);

            if ($order->status === Order::STATUS_CANCELLED) {
                DB::commit();
                return;
            }

            $this->restoreStock($order);

            $order->update([
                'status' => Order::STATUS_CANCELLED
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE ITEMS
    |--------------------------------------------------------------------------
    */
    private function createOrderItems(
        Order $order,
        array $items,
        Outlet $outlet,
        bool $checkStock = true
    ): void {
        $user = $this->currentUser();

        foreach ($items as $item) {
            $product = $outlet->products()
                ->where('products.id', $item['product_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $stock = (int) $product->pivot->stock;
            $qty   = (int) $item['qty'];

            if ($checkStock && $stock < $qty) {
                throw new \Exception(
                    "Stok {$product->name} tidak cukup"
                );
            }

            $price = (int) $product->pivot->price;

            OrderItem::create([
                'order_id'    => $order->id,
                'product_id'  => $product->id,
                'qty'         => $qty,
                'price'       => $price,
                'total_price' => $price * $qty,
                'notes'       => $item['notes'] ?? null,
            ]);

            if ($checkStock) {
                $newStock = $stock - $qty;

                $outlet->products()
                    ->updateExistingPivot(
                        $product->id,
                        ['stock' => $newStock]
                    );

                StockHistory::create([
                    'outlet_id'   => $outlet->id,
                    'product_id'  => $product->id,
                    'user_id'     => $user->id,
                    'type'        => 'sale',
                    'quantity'    => -$qty,
                    'final_stock' => $newStock,
                    'reference'   => $order->invoice_number,
                ]);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | RESTORE STOCK
    |--------------------------------------------------------------------------
    */
    private function restoreStock(Order $order): void
    {
        foreach ($order->items as $item) {
            $pivot = $order->outlet
                ->products()
                ->where('products.id', $item->product_id)
                ->first();

            if (!$pivot) {
                continue;
            }

            $stock = (int) $pivot->pivot->stock;

            $newStock = $stock + $item->qty;

            $order->outlet->products()
                ->updateExistingPivot(
                    $item->product_id,
                    ['stock' => $newStock]
                );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PAYMENT CREATE
    |--------------------------------------------------------------------------
    */
    private function createPayment(
        Order $order,
        int $amount,
        string $method
    ): Payment {
        return Payment::create([
            'order_id'      => $order->id,
            'amount_paid'   => $amount,
            'change_amount' => max(
                0,
                $amount - $order->total_price
            ),
            'method'        => strtolower($method),
            'paid_at'       => now(),
            'paid_by'       => $this->currentUser()->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | HISTORY
    |--------------------------------------------------------------------------
    */
    public function syncHistoryTransaction(Order $order): void
    {
        $this->storeHistoryTransaction($order);
    }

    private function storeHistoryTransaction(Order $order): void
    {
        $order->load(['payments', 'items.product']);

        $lastPayment = $order->payments
            ->sortByDesc('id')
            ->first();

        HistoryTransaction::updateOrCreate(
            ['order_id' => $order->id],
            [
                'outlet_id'      => $order->outlet_id,
                'payment_id'     => $lastPayment?->id,
                'invoice_number' => $order->invoice_number,
                'customer_name'  => $order->customer_name,
                'subtotal_price' => $order->subtotal_price,
                'discount_amount'=> $order->discount_amount,
                'tax_amount'     => $order->tax_amount,
                'total_price'    => $order->total_price,
                'paid_amount'    => $order->payments->sum('amount_paid'),
                'change_amount'  => $order->payments->sum('change_amount'),
                'payment_method' => $lastPayment?->method,
                'paid_at'        => $lastPayment?->paid_at,
                'cashier_id'     => $lastPayment?->paid_by,
                'status'         => Order::STATUS_PAID,
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | INVOICE
    |--------------------------------------------------------------------------
    */
    private function generateInvoiceNumber(int $outletId): string
    {
        $date = now()->format('Ymd');

        $counter = InvoiceCounter::lockForUpdate()
            ->firstOrCreate(
                [
                    'outlet_id' => $outletId,
                    'date'      => $date
                ],
                [
                    'last_number' => 0
                ]
            );

        $counter->increment('last_number');

        $counter->refresh();

        $num = str_pad(
            $counter->last_number,
            4,
            '0',
            STR_PAD_LEFT
        );

        return "INV-{$date}-{$num}";
    }

    /*
    |--------------------------------------------------------------------------
    | DISCOUNT + TAX
    |--------------------------------------------------------------------------
    */
    private function handleAdjustments(
        Order $order,
        array $data
    ): void {
        $order->update([
            'discount_id' => $data['discount_id'] ?? null,
            'tax_id'      => $data['tax_id'] ?? null,
            'manual_discount_type'
                => $data['manual_discount_type'] ?? null,
            'manual_discount_value'
                => $data['manual_discount_value'] ?? 0,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | AUTH
    |--------------------------------------------------------------------------
    */
    private function canAccessOutlet(int $outletId): bool
    {
        $user = $this->currentUser();

        if ($user->role === 'developer') {
            return true;
        }

        if ($user->role === 'manager') {
            return Outlet::where(
                'owner_id',
                $user->id
            )->where(
                'id',
                $outletId
            )->exists();
        }

        return (int) $user->outlet_id === $outletId;
    }

    private function canAccessOrder(Order $order): bool
    {
        return $this->canAccessOutlet(
            $order->outlet_id
        );
    }

    private function currentUser(): User
    {
        $user = auth()->user();

        if (!$user instanceof User) {
            throw new \RuntimeException(
                'Unauthenticated'
            );
        }

        return $user;
    }
}
