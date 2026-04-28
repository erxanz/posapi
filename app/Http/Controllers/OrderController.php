<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\Tax;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Midtrans\Config;
use Midtrans\Snap;

class OrderController extends Controller
{
    public function __construct(private OrderService $orderService) {}

    /**
     * List order
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        $query = Order::with(['items.product', 'table', 'user', 'outlet']);

        if ($user->role === 'karyawan') {
            $query->where('outlet_id', $user->outlet_id);
        } elseif ($user->role === 'manager') {
            $outletIds = Outlet::where('owner_id', $user->id)->pluck('id');
            $query->whereIn('outlet_id', $outletIds);
        }

        if ($request->filled('search')) {
            $query->where('invoice_number', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $limit = $request->input('limit', 10);

        return response()->json($query->latest()->paginate($limit));
    }

    // DISABLED: Use checkoutOrder instead
    public function store(Request $request)
    {
        abort(410, 'Use checkoutOrder endpoint');
    }

    /**
     * Tambah Item (CART)
     */
    public function addItem(Request $request, $orderId)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'qty' => 'required|integer|min:1'
        ]);

        $order = Order::where('id', $orderId)
            ->where('outlet_id', auth()->user()->outlet_id)
            ->firstOrFail();

        // hanya order pending yang bisa diubah
        if ($order->status !== 'pending') {
            return response()->json(['message' => 'Order sudah tidak bisa diubah'], 400);
        }

        // Ambil konfigurasi produk berdasarkan outlet di pivot.
        $product = Outlet::query()
            ->findOrFail($order->outlet_id)
            ->products()
            ->where('products.id', $request->product_id)
            ->wherePivot('is_active', true)
            ->firstOrFail();

        $requestQty = (int) $request->qty;
        $existingQty = (int) ($order->items()->where('product_id', $request->product_id)->value('qty') ?? 0);
        $targetQty = $existingQty + $requestQty;
        $availableStock = (int) $product->pivot->stock;

        if ($targetQty > $availableStock) {
            return response()->json([
                'message' => "Stok {$product->name} tidak cukup (maksimal {$availableStock})"
            ], 400);
        }

        $price = (int) $product->pivot->price;

        DB::beginTransaction();

        try {
            $item = $order->items()->where('product_id', $product->id)->first();

            if ($item) {
                $item->qty += $requestQty;
                $item->total_price = $item->qty * $item->price;
                $item->save();
            } else {
                $order->items()->create([
                    'product_id' => $product->id,
                    'qty' => $requestQty,
                    'price' => $price,
                    'total_price' => $price * $requestQty
                ]);
            }

            $this->updateTotal($order);

            DB::commit();

            return response()->json($order->load('items.product'), 201);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update total order
     */
    private function updateTotal($order)
    {
        $this->recalculateOrderTotals($order);
    }

    private function recalculateOrderTotals(Order $order, array $overrides = []): void
    {
        $normalizedOverrides = $this->normalizeLegacyAdjustmentPayload($overrides);
        $order->recalculateTotals($normalizedOverrides);
    }

    /**
     * Detail order
     */
    public function show(Order $order)
    {
        if ($order->outlet_id !== auth()->user()->outlet_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(
            $order->load('items.product', 'table')
        );
    }

    /**
     * Hapus item dari order pending
     */
    public function removeItem($orderId, $itemId)
    {
        $order = Order::where('id', $orderId)
            ->where('outlet_id', auth()->user()->outlet_id)
            ->firstOrFail();

        if ($order->status !== 'pending') {
            return response()->json(['message' => 'Order sudah tidak bisa diubah'], 400);
        }

        $item = $order->items()->findOrFail($itemId);
        $item->delete();

        $this->updateTotal($order);

        return response()->json($order->load('items.product'));
    }

    /**
     * Public order (QR) using service
     */
    public function publicOrder(Request $request)
    {
        $validated = $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'table_id' => 'required|exists:tables,id',
            'customer_name' => 'nullable|string|max:100',
            'manual_discount_type' => 'nullable|in:percentage,nominal',
            'manual_discount_value' => 'nullable|integer|min:0',
            'discount_id' => 'nullable|exists:discounts,id',
            'discount_type' => 'nullable|in:percentage,nominal',
            'discount_value' => 'nullable|integer|min:0',
            'tax_id' => 'nullable|exists:taxes,id',
            'tax_type' => 'nullable|in:percentage,nominal,fixed',
            'tax_value' => 'nullable|integer|min:0',
            'tax_amount' => 'nullable|integer|min:0',
            'tax_breakdown' => 'nullable|array',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.price' => 'nullable|integer|min:1',
            'items.*.notes' => 'nullable|string|max:500',
        ]);

        $validated = $this->normalizeLegacyAdjustmentPayload($validated);

        try {
            $result = $this->orderService->createPublicOrder($validated);

            return response()->json($result, 201);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Checkout order using service
     */
    public function checkoutOrder(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'outlet_id' => 'nullable|exists:outlets,id',
            'table_id' => 'required|exists:tables,id',
            'customer_name' => 'nullable|string|max:100',

            // DISKON
            'manual_discount_type' => 'nullable|in:percentage,nominal',
            'manual_discount_value' => 'nullable|integer|min:0',
            'discount_id' => 'nullable|exists:discounts,id',
            'discount_type' => 'nullable|in:percentage,nominal',
            'discount_value' => 'nullable|integer|min:0',

            // PAJAK
            'tax_id' => 'nullable|exists:taxes,id',
            'tax_type' => 'nullable|in:percentage,nominal,fixed',
            'tax_value' => 'nullable|integer|min:0',
            'tax_amount' => 'nullable|integer|min:0',
            'tax_breakdown' => 'nullable|array',

            // ITEM
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.price' => 'nullable|integer|min:1',
            'items.*.notes' => 'nullable|string|max:500',

            // PAYMENT
            'payment_method' => 'required|string|max:50',
            'amount_paid' => 'nullable|numeric|min:0|required_without:paid_amount',
            'paid_amount' => 'nullable|numeric|min:0|required_without:amount_paid',
        ]);

        if ($user->role === 'karyawan' && empty($validated['outlet_id'])) {
            $validated['outlet_id'] = $user->outlet_id;
        }

        $validated['amount_paid'] = (int) ($validated['amount_paid'] ?? $validated['paid_amount']);
        $validated = $this->normalizeLegacyAdjustmentPayload($validated);

        try {

            /**
             * MIDTRANS
             */
            if (in_array($validated['payment_method'], ['Qris', 'Card'])) {

                $result = $this->orderService
                    ->createCheckoutOrderForMidtrans(
                        $validated,
                        $validated['outlet_id'] ?? null
                    );

                $order = $result['order'];

                /**
                 * PENTING: Reload order untuk memastikan discount_amount & tax_amount
                 * terisi dengan benar dari database
                 */
                $order = Order::with('items.product', 'table')
                    ->findOrFail($order->id);

                Config::$serverKey = env('MIDTRANS_SERVER_KEY');
                Config::$isProduction = env('MIDTRANS_IS_PRODUCTION', false);
                Config::$isSanitized = true;
                Config::$is3ds = true;

                $itemDetails = [];

                foreach ($order->items as $item) {
                    $itemDetails[] = [
                        'id' => (string) $item->product_id,
                        'name' => substr($item->product->name, 0, 50),
                        'price' => (int) $item->price,
                        'quantity' => (int) $item->qty,
                    ];
                }

                /**
                 * DISKON MASUK MIDTRANS
                 * Pastikan discount_amount sudah terhitung
                 */
                $discountAmount = (int) ($order->discount_amount ?? 0);
                if ($discountAmount > 0) {
                    $itemDetails[] = [
                        'id' => 'DISCOUNT',
                        'name' => 'Discount',
                        'price' => -abs($discountAmount),
                        'quantity' => 1,
                    ];
                }

                /**
                 * PAJAK MASUK MIDTRANS
                 * Pastikan tax_amount sudah terhitung
                 */
                $taxAmount = (int) ($order->tax_amount ?? 0);
                if ($taxAmount > 0) {
                    $itemDetails[] = [
                        'id' => 'TAX',
                        'name' => 'Tax',
                        'price' => $taxAmount,
                        'quantity' => 1,
                    ];
                }

                $params = [
                    'transaction_details' => [
                        'order_id' => $order->invoice_number,
                        'gross_amount' => (int) $order->total_price,
                    ],
                    'customer_details' => [
                        'first_name' => $order->customer_name ?: 'Customer POS',
                    ],
                    'item_details' => $itemDetails,
                ];

                $paymentUrl = Snap::createTransaction($params)->redirect_url;

                return response()->json([
                    'success' => true,
                    'message' => 'Order berhasil dibuat',
                    'data' => [
                        'order' => $order->load('items.product', 'table'),
                        'redirect_url' => $paymentUrl
                    ]
                ], 201);
            }

            /**
             * CASH
             */
            $result = $this->orderService->createCheckoutOrder(
                $validated,
                $validated['outlet_id'] ?? null
            );

            return response()->json($result, 201);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Payment (split support) using service
     */
    public function pay(Request $request, Order $order)
    {
        // Backward compatibility: allow non-split payload on /payments endpoint.
        if (!$request->has('payments') && $request->filled('amount_paid')) {
            $request->merge([
                'payments' => [[
                    'amount_paid' => (int) $request->input('amount_paid'),
                    'method' => $request->input('method', 'cash'),
                    'reference_no' => $request->input('reference_no'),
                ]],
            ]);
        }

        $validated = $request->validate([
            'payments' => 'required|array|min:1',
            'payments.*.amount_paid' => 'required|integer|min:1',
            'payments.*.method' => 'required|string|max:50',
            'payments.*.reference_no' => 'nullable|string|max:100',
        ]);

        try {
            $result = $this->orderService->processPayments($order, $validated['payments']);

            $status = $result['is_paid'] ? 200 : 202;

            return response()->json([
                'message' => $result['is_paid'] ? 'Order lunas' : 'Pembayaran tercatat',
                'order' => $result['order'],
                'payment_summary' => [
                    'order_total' => $result['order']->total_price,
                    'effective_paid' => $result['order']->payments->sum(fn($p) => $p->amount_paid - $p->change_amount),
                    'remaining' => $result['remaining'],
                ],
            ], $status);
        } catch (\Throwable $e) {
            $status = str_contains(strtolower($e->getMessage()), 'forbidden') ? 403 : 400;

            return response()->json([
                'message' => $e->getMessage(),
            ], $status);
        }
    }

    /**
     * Simple checkout alias for pay
     */
    public function checkout(Request $request, Order $order)
    {
        $validated = $request->validate([
            'amount_paid' => 'nullable|integer|min:1|required_without:paid_amount',
            'paid_amount' => 'nullable|integer|min:1|required_without:amount_paid',
            'method' => 'nullable|string|max:50',
            'reference_no' => 'nullable|string|max:100',
        ]);

        $amountPaid = (int) ($validated['amount_paid'] ?? $validated['paid_amount']);

        $request->merge([
            'payments' => [[
                'amount_paid' => $amountPaid,
                'method' => $validated['method'] ?? 'cash',
                'reference_no' => $validated['reference_no'] ?? null,
            ]],
        ]);

        return $this->pay($request, $order);
    }

    /**
     * Update adjustments
     */
    public function updateAdjustments(Request $request, Order $order)
    {
        if ($order->status !== Order::STATUS_PENDING) {
            return response()->json(['message' => 'Only pending orders'], 400);
        }

        $validated = $request->validate([
            'manual_discount_type' => 'nullable|in:percentage,nominal',
            'manual_discount_value' => 'nullable|integer|min:0',
            'discount_id' => 'nullable|exists:discounts,id',
            'discount_type' => 'nullable|in:percentage,nominal',
            'discount_value' => 'nullable|integer|min:0',
            'tax_id' => 'nullable|exists:taxes,id',
            'tax_type' => 'nullable|in:percentage,nominal,fixed',
            'tax_value' => 'nullable|integer|min:0',
            'tax_amount' => 'nullable|integer|min:0',
            'tax_breakdown' => 'nullable|array',
        ]);

        $validated = $this->normalizeLegacyAdjustmentPayload($validated);

        $order->update($validated);
        $order->recalculateTotals($validated);

        return response()->json([
            'message' => 'Adjustments updated',
            'order' => $order->fresh()->load('items.product', 'table'),
        ]);
    }

    /**
     * Void items (keep as is, refactored minimally)
     */
    public function voidItems(Request $request, Order $order)
    {
        $this->authorizeOutletAccess($order);

        if ($order->status === Order::STATUS_CANCELLED) {
            return response()->json(['message' => 'Order cancelled'], 400);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:255',
            'items' => 'required|array',
            'items.*.id' => 'required|exists:order_items,id',
            'items.*.cancelled_qty' => 'required|integer|min:0|max:100', // Add max
        ]);

        // Implementation remains similar but use $order->recalculateTotals() at end
        // (shortened for brevity, full logic as original but cleaned)

        DB::beginTransaction();
        try {
            $actionDetails = [];
            $outlet = $order->outlet;

            foreach ($validated['items'] as $inputItem) {
                $item = $order->items()->find($inputItem['id']);
                if (!$item) continue;

                $oldQty = $item->cancelled_qty;
                $newQty = $inputItem['cancelled_qty'];
                $diff = $newQty - $oldQty;

                if ($diff !== 0) {
                    $action = $diff > 0 ? 'Void' : 'Restore';
                    $actionDetails[] = "{$action} " . abs($diff) . "x {$item->product->name}";

                    $item->update([
                        'cancelled_qty' => $newQty,
                        'total_price' => ($item->qty - $newQty) * $item->price,
                    ]);

                    // Stock adjust...
                    $pivotStock = $outlet->products()->where('products.id', $item->product_id)->first()?->pivot->stock ?? 0;
                    $newStock = $pivotStock + $diff;
                    $outlet->products()->updateExistingPivot($item->product_id, ['stock' => $newStock]);

                    \App\Models\StockHistory::create([
                        'outlet_id' => $order->outlet_id,
                        'product_id' => $item->product_id,
                        'user_id' => auth()->id(),
                        'type' => 'void',
                        'quantity' => $diff,
                        'final_stock' => $newStock,
                        'reference' => 'Void: ' . $order->invoice_number,
                    ]);
                }
            }

            if ($actionDetails) {
                $logs = $order->logs ?? [];
                array_unshift($logs, [
                    'date' => now()->format('d M Y H:i'),
                    'action' => implode(', ', $actionDetails),
                    'reason' => $validated['reason'],
                    'by' => auth()->user()->name ?? 'system',
                ]);
                $order->update(['logs' => $logs]);

                // --- PERBAIKAN: Hitung ulang Subtotal, Diskon & Pajak secara Dinamis ---
                $order->refresh(); // Ambil data item terbaru setelah ada yang di-void
                $newSubtotal = $order->items->sum('total_price');

                // 1. Hitung ulang diskon (menyesuaikan subtotal baru)
                $newDiscountAmount = 0;
                if ($order->manual_discount_type === 'percentage') {
                    $calc = $newSubtotal * ($order->manual_discount_value / 100);
                    if ($order->discount_id) {
                        $discountModel = \App\Models\Discount::find($order->discount_id);
                        if ($discountModel && $discountModel->max_discount && $calc > $discountModel->max_discount) {
                            $calc = $discountModel->max_discount;
                        }
                    }
                    $newDiscountAmount = (int) $calc;
                } else {
                    // Jika diskon nominal (tetap), pastikan diskon tidak membuat minus
                    $oldDiscount = $order->discount_amount ?? 0;
                    $newDiscountAmount = (int) min($oldDiscount, $newSubtotal);
                }

                $amountAfterDiscount = max(0, $newSubtotal - $newDiscountAmount);

                // 2. Hitung ulang pajak secara dinamis dari subtotal setelah diskon
                $newTaxAmount = 0;
                if ($order->tax_id) {
                    $tax = \App\Models\Tax::find($order->tax_id);
                    if ($tax) {
                        if ($tax->type === 'percentage') {
                            // Hitung persen dari sisa tagihan
                            $newTaxAmount = (int) round($amountAfterDiscount * ((float) $tax->rate / 100));
                        } else {
                            // Pajak nominal tetap (misal biaya admin)
                            $newTaxAmount = (int) $tax->rate;
                        }
                    }
                } elseif ($order->tax_amount > 0 && $order->subtotal_price > 0) {
                    // Fallback proporsional jika tidak ada ID pajak tapi transaksi aslinya punya pajak
                    $oldAmountAfterDiscount = max(0, $order->subtotal_price - $order->discount_amount);
                    if ($oldAmountAfterDiscount > 0) {
                        $rate = $order->tax_amount / $oldAmountAfterDiscount;
                        $newTaxAmount = (int) round($amountAfterDiscount * $rate);
                    }
                }

                // Masukkan hasil perhitungan dinamis ke fungsi recalculateTotals
                $order->recalculateTotals([
                    'discount_amount' => $newDiscountAmount,
                    'tax_amount' => $newTaxAmount
                ]);
                // --- END PERBAIKAN ---

                // Keep transaction history consistent after void/restore on paid orders.
                if ($order->status === Order::STATUS_PAID) {
                    $this->orderService->syncHistoryTransaction($order->fresh());
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Void processed',
                'order' => $order->fresh()->load('items.product', 'table', 'user'),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Update multiple order items qty by ID (for kitchen/customer sync)
     */
    public function updateItems(Request $request, Order $order)
    {
        $this->authorizeOutletAccess($order);

        if ($order->status !== Order::STATUS_PENDING) {
            return response()->json(['message' => 'Only pending orders can be updated'], 400);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|exists:order_items,id',
            'items.*.qty' => 'required|integer|min:0',
        ]);

        DB::beginTransaction();
        try {
            foreach ($validated['items'] as $itemData) {
                \App\Models\OrderItem::where('id', $itemData['id'])
                    ->where('order_id', $order->id)
                    ->lockForUpdate()
                    ->update([
                        'qty' => $itemData['qty'],
                        'total_price' => $itemData['qty'] * (
                            \App\Models\OrderItem::where('id', $itemData['id'])
                            ->where('order_id', $order->id)
                            ->value('price') ?? 0
                        ),
                    ]);
            }

            $this->recalculateOrderTotals($order);
            DB::commit();

            return response()->json([
                'message' => 'Order items updated',
                'order' => $order->fresh()->load('items.product'),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * No delete
     */
    public function destroy(Order $order)
    {
        return response()->json(['message' => 'Delete not allowed'], 400);
    }

    private function authorizeOutletAccess(Order $order): void
    {
        $user = auth()->user();
        if ($user->role !== 'developer' && $user->outlet_id !== $order->outlet_id) {
            abort(403);
        }
    }

    private function normalizeLegacyAdjustmentPayload(array $payload): array
    {
        if (!isset($payload['manual_discount_type']) && isset($payload['discount_type'])) {
            $payload['manual_discount_type'] = $payload['discount_type'];
        }

        if (!isset($payload['manual_discount_value']) && isset($payload['discount_value'])) {
            $payload['manual_discount_value'] = (int) $payload['discount_value'];
        }

        if (!isset($payload['tax_id']) && isset($payload['tax_type']) && isset($payload['tax_value'])) {
            $tax = Tax::query()
                ->where('type', $payload['tax_type'])
                ->where('active', true)
                ->get()
                ->first(function (Tax $tax) use ($payload) {
                    $expectedValue = $tax->type === 'percentage'
                        ? (int) round(((float) $tax->rate) * 100)
                        : (int) round((float) $tax->rate);

                    return $expectedValue === (int) $payload['tax_value'];
                });

            if ($tax) {
                $payload['tax_id'] = $tax->id;
            }
        }

        return $payload;
    }

    /**
     * Webhook callback dari Midtrans
     */
    public function midtransCallback(Request $request)
    {
        $serverKey = env('MIDTRANS_SERVER_KEY');
        $hashed = hash("sha512", $request->order_id . $request->status_code . $request->gross_amount . $serverKey);

        // Verifikasi kalau request beneran dari Midtrans
        if ($hashed == $request->signature_key) {
            $order = Order::where('invoice_number', $request->order_id)->first();

            if ($order) {
                DB::beginTransaction();

                try {
                    if ($request->transaction_status == 'capture' || $request->transaction_status == 'settlement') {
                        // Pembayaran sukses
                        $order->update(['status' => Order::STATUS_PAID]);

                        // Buat payment record
                        \App\Models\Payment::create([
                            'order_id' => $order->id,
                            'amount_paid' => (int) $request->gross_amount,
                            'change_amount' => 0,
                            'method' => 'midtrans',
                            'reference_no' => $request->transaction_id ?? null,
                            'paid_at' => now(),
                            'paid_by' => null, // Dari Midtrans, bukan dari user manual
                        ]);

                        // Update table status
                        if ($order->table_id) {
                            $order->table->update(['status' => 'available']);
                        }

                        // Store history transaction
                        $this->orderService->syncHistoryTransaction($order->fresh());

                    } else if ($request->transaction_status == 'cancel' || $request->transaction_status == 'deny' || $request->transaction_status == 'expire') {
                        // Pembayaran dibatalkan/ditolak/expired
                        $order->update(['status' => Order::STATUS_CANCELLED]);
                    }

                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollBack();
                    \Log::error('Midtrans callback error: ' . $e->getMessage());
                }
            }
        }

        return response()->json(['message' => 'Callback received']);
    }
}
