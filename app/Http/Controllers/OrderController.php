<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\Tax;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\CancelOrderItemRequest;
use App\Models\OrderItem;
use Midtrans\Config;
use Midtrans\Snap;

class OrderController extends Controller
{
    public function __construct(private OrderService $orderService) {
        $this->middleware('auth:sanctum')->except(['publicOrder', 'midtransCallback', 'publicShow']);
    }

    /**
     * Detail order untuk public (QR POS)
     */
    public function publicShow($id)
    {
        $order = Order::with(['items.product', 'table', 'payments', 'latestAcceptance', 'discount'])->findOrFail($id);

        // Pastikan breakdown pajak aman dibaca sebagai array oleh Vue
        if (is_string($order->tax_breakdown)) {
            $order->tax_breakdown = json_decode($order->tax_breakdown, true);
        }

        return response()->json([
            'success' => true,
            'data' => $order
        ]);
    }

    /**
     * List order
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        $query = Order::with(['items.product', 'table', 'user', 'outlet', 'latestAcceptance']);

        if ($request->filled('status') && $request->status === Order::STATUS_PENDING) {
            $query->with(['payments:id,order_id,method,paid_at']);
        }

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

            if (in_array($request->status, [Order::STATUS_PENDING, Order::STATUS_PAID], true)) {
                $query->whereDoesntHave('acceptances', function ($acceptanceQuery) {
                    $acceptanceQuery->whereNotNull('accepted_at');
                });
            }
        }

        $limit = $request->input('limit', 10);
        $paginator = $query->latest()->paginate($limit);

        $paginator->getCollection()->transform(function (Order $order) {
            $paymentMethod = $order->payment_method;

            if (empty($paymentMethod) && $order->relationLoaded('payments') && $order->payments) {
                $payment = $order->payments->sortBy(fn($p) => $p->paid_at ?? $p->created_at)->first();
                $paymentMethod = $payment?->method;
            }

            $order->payment_method = $paymentMethod;
            return $order;
        });

        return response()->json($paginator);
    }

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

        if ($order->status !== 'pending') {
            return response()->json(['message' => 'Order sudah tidak bisa diubah'], 400);
        }

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
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

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
            $order->load('items.product', 'table', 'payments', 'latestAcceptance', 'discount')
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
        $outlet = \App\Models\Outlet::find($order->outlet_id);
        $product = $outlet->products()->where('products.id', $item->product_id)->first();

        if ($product) {
            $newStock = $product->pivot->stock + $item->qty;
            $outlet->products()->updateExistingPivot($item->product_id, ['stock' => $newStock]);

            \App\Models\StockHistory::create([
                'outlet_id' => $order->outlet_id,
                'product_id' => $item->product_id,
                'user_id' => auth()->id(),
                'type' => 'restore',
                'quantity' => $item->qty,
                'final_stock' => $newStock,
                'reference' => 'Remove Item: ' . $order->invoice_number,
            ]);
        }

        $item->delete();
        $this->updateTotal($order);

        return response()->json($order->load('items.product'));
    }

    /**
     * Cancel a single item safely inside a DB transaction.
     */
    public function cancelItem(CancelOrderItemRequest $request, Order $order, OrderItem $item)
    {
        $this->authorizeOutletAccess($order);

        if ($order->status !== Order::STATUS_PENDING) {
            return response()->json(['message' => 'Only pending orders can be modified'], 400);
        }

        if ($item->order_id !== $order->id) {
            return response()->json(['message' => 'Item does not belong to this order'], 404);
        }

        $cancelQty = (int) $request->input('cancel_qty');

        DB::beginTransaction();
        try {
            $locked = OrderItem::where('id', $item->id)
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $remaining = max(0, $locked->qty - $locked->cancelled_qty);

            if ($cancelQty > $remaining) {
                return response()->json(['message' => 'cancel_qty exceeds remaining quantity'], 400);
            }

            $oldCancelled = $locked->cancelled_qty;
            $locked->applyCancellation($cancelQty);
            $diff = $locked->cancelled_qty - $oldCancelled;

            $outlet = $order->outlet;
            if ($outlet) {
                $productPivot = $outlet->products()->where('products.id', $locked->product_id)->first();
                $pivotStock = $productPivot?->pivot->stock ?? 0;
                $newStock = $pivotStock + $diff;
                if ($productPivot) {
                    $outlet->products()->updateExistingPivot($locked->product_id, ['stock' => $newStock]);

                    \App\Models\StockHistory::create([
                        'outlet_id' => $order->outlet_id,
                        'product_id' => $locked->product_id,
                        'user_id' => auth()->id(),
                        'type' => 'void',
                        'quantity' => $diff,
                        'final_stock' => $newStock,
                        'reference' => 'Cancel Item: ' . $order->invoice_number,
                    ]);
                }
            }

            $logs = $order->logs ?? [];
            array_unshift($logs, [
                'date' => now()->format('d M Y H:i'),
                'action' => "Cancel {$diff}x " . ($locked->product->name ?? 'item'),
                'reason' => $request->input('reason') ?? null,
                'by' => auth()->user()->name ?? 'system',
            ]);
            $order->update(['logs' => $logs]);

            $order->refresh();
            $order->recalculateTotals();

            if ($order->status === Order::STATUS_PAID) {
                $this->orderService->syncHistoryTransaction($order->fresh());
            }

            DB::commit();

            return response()->json([
                'message' => 'Item cancelled',
                'order' => $order->fresh()->load('items.product', 'table'),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
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
            'payment_method' => 'required|string|max:50',
            'manual_discount_type' => 'nullable|in:percentage,nominal',
            'manual_discount_value' => 'nullable|integer|min:0',
            'discount' => 'nullable',
            'discounts' => 'nullable|array',
            'discount_ids' => 'nullable|array',
            'discount_ids.*' => 'exists:discounts,id',
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

            $order = is_array($result) ? ($result['order'] ?? null) : $result;
            if (!$order && is_array($result) && isset($result['id'])) {
                $order = Order::find($result['id']);
            }

            // Force load dengan relasi discount agar data diskon terbaca
            $order = Order::with(['items.product', 'table', 'discount'])->findOrFail($order->id);

            // KUNCI UTAMA: Jalankan ulang hitung total bawaan model agar kolom database tersinkronisasi 100%
            $order->recalculateTotals();
            $order->refresh();

            $methodStr = strtolower($request->payment_method);
            if (in_array($methodStr, ['midtrans', 'qris', 'card'])) {

                $serverKey = $order->midtrans_server_key_used;
                if (empty($serverKey) && $order->outlet_id) {
                    $ownerId = Outlet::whereKey($order->outlet_id)->value('owner_id');
                    $serverKey = \App\Models\User::whereKey($ownerId)->value('midtrans_server_key');
                }

                if (empty($serverKey)) {
                    $serverKey = env('MIDTRANS_SERVER_KEY');
                }

                \Midtrans\Config::$serverKey = $serverKey;
                \Midtrans\Config::$isProduction = env('MIDTRANS_IS_PRODUCTION', false);
                \Midtrans\Config::$isSanitized = true;
                \Midtrans\Config::$is3ds = true;

                $itemDetails = [];
                foreach ($order->items as $item) {
                    $itemDetails[] = [
                        'id' => (string) $item->product_id,
                        'name' => substr($item->product->name, 0, 50),
                        'price' => (int) $item->price,
                        'quantity' => (int) $item->qty,
                    ];
                }

                $discountAmount = (int) ($order->discount_amount ?? 0);
                if ($discountAmount > 0) {
                    $itemDetails[] = [
                        'id' => 'DISCOUNT',
                        'name' => 'Discount',
                        'price' => -$discountAmount,
                        'quantity' => 1,
                    ];
                }

                $taxAmount = (int) ($order->tax_amount ?? 0);
                if ($taxAmount > 0) {
                    $itemDetails[] = [
                        'id' => 'TAX',
                        'name' => 'Tax',
                        'price' => $taxAmount,
                        'quantity' => 1,
                    ];
                }

                // AMBIL TOTAL AKHIR LANGSUNG DARI MODEL (Aman dari pembulatan/perbedaan loop)
                $finalGrossAmount = (int) $order->total_price;

                $params = [
                    'transaction_details' => [
                        'order_id' => $order->invoice_number,
                        'gross_amount' => $finalGrossAmount,
                    ],
                    'customer_details' => [
                        'first_name' => $order->customer_name ?: 'Customer POS',
                    ],
                    'item_details' => $itemDetails,
                    'callbacks' => [
                        'finish' => env('FRONTEND_URL', 'https://pos.etres.my.id') . '/status/' . $order->id,
                        'unfinish' => env('FRONTEND_URL', 'https://pos.etres.my.id') . '/status/' . $order->id,
                        'error' => env('FRONTEND_URL', 'https://pos.etres.my.id') . '/status/' . $order->id,
                    ],
                ];

                if ($methodStr === 'qris' || $methodStr === 'midtrans') {
                    $params['enabled_payments'] = ['gopay'];
                } elseif ($methodStr === 'card') {
                    $params['enabled_payments'] = ['credit_card'];
                }

                $paymentUrl = \Midtrans\Snap::createTransaction($params)->redirect_url;

                return response()->json([
                    'success' => true,
                    'message' => 'Order berhasil dibuat',
                    'data' => [
                        'order' => $order->load('items.product', 'table'),
                        'redirect_url' => $paymentUrl
                    ]
                ], 201);
            }

            $tableId = $order->table_id ?? null;
            if ($tableId) {
                \App\Models\Table::whereKey($tableId)->update([
                    'status' => 'reserved',
                    'reserved_until' => now()->addMinutes(20),
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $order
            ], 201);

        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
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
            'manual_discount_type' => 'nullable|in:percentage,nominal',
            'manual_discount_value' => 'nullable|integer|min:0',
            'discount' => 'nullable',
            'discounts' => 'nullable|array',
            'discount_id' => 'nullable|exists:discounts,id',
            'discount_ids' => 'nullable|array',
            'discount_ids.*' => 'exists:discounts,id',
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
            $methodStr = strtolower($validated['payment_method'] ?? '');

            if (in_array($methodStr, ['qris', 'card', 'credit_card', 'midtrans'])) {
                $result = $this->orderService->createCheckoutOrderForMidtrans(
                    $validated,
                    $validated['outlet_id'] ?? null
                );

                $order = $result['order'];
                $order = Order::with(['items.product', 'table', 'discount'])->findOrFail($order->id);

                // RE-CALCULATE TOTALS AGAR DISKON DAN PAJAK TERSINKRONISASI KE KOLOM DATABASE
                $order->recalculateTotals();
                $order->refresh();

                Config::$serverKey = env('MIDTRANS_SERVER_KEY');
                Config::$isProduction = env('MIDTRANS_IS_PRODUCTION', false);
                Config::$isSanitized = true;
                Config::$is3ds = true;

                if ($user && $user->role === 'manager') {
                    if (!empty($user->midtrans_server_key)) {
                        Config::$serverKey = $user->midtrans_server_key;
                    }
                }

                $itemDetails = [];
                foreach ($order->items as $item) {
                    $itemDetails[] = [
                        'id' => (string) $item->product_id,
                        'name' => substr($item->product->name, 0, 50),
                        'price' => (int) $item->price,
                        'quantity' => (int) $item->qty,
                    ];
                }

                $discountAmount = (int) ($order->discount_amount ?? 0);
                if ($discountAmount > 0) {
                    $itemDetails[] = [
                        'id' => 'DISCOUNT',
                        'name' => 'Discount',
                        'price' => -$discountAmount,
                        'quantity' => 1,
                    ];
                }

                $taxAmount = (int) ($order->tax_amount ?? 0);
                if ($taxAmount > 0) {
                    $itemDetails[] = [
                        'id' => 'TAX',
                        'name' => 'Tax',
                        'price' => $taxAmount,
                        'quantity' => 1,
                    ];
                }

                // AMBIL TOTAL AKHIR LANGSUNG DARI ATRIBUT DATABASE TERINTEGRASI
                $finalGrossAmount = (int) $order->total_price;

                $params = [
                    'transaction_details' => [
                        'order_id' => $order->invoice_number,
                        'gross_amount' => $finalGrossAmount,
                    ],
                    'customer_details' => [
                        'first_name' => $order->customer_name ?: 'Customer POS',
                    ],
                    'item_details' => $itemDetails,
                    'callbacks' => [
                        'finish' => env('FRONTEND_URL', 'https://pos.etres.my.id') . '/status/' . $order->id,
                        'unfinish' => env('FRONTEND_URL', 'https://pos.etres.my.id') . '/status/' . $order->id,
                        'error' => env('FRONTEND_URL', 'https://pos.etres.my.id') . '/status/' . $order->id,
                    ]
                ];

                if ($methodStr === 'qris' || $methodStr === 'midtrans') {
                    $params['enabled_payments'] = ['gopay'];
                } elseif ($methodStr === 'card' || $methodStr === 'credit_card') {
                    $params['enabled_payments'] = ['credit_card'];
                }

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

            // CASH FLOW
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
                    'order_total' => $result['order']->payableTotal(),
                    'effective_paid' => $result['order']->payments->sum(fn($p) => $p->amount_paid - $p->change_amount),
                    'remaining' => $result['remaining'],
                ],
            ], $status);
        } catch (\Throwable $e) {
            $status = str_contains(strtolower($e->getMessage()), 'forbidden') ? 403 : 400;
            return response()->json(['message' => $e->getMessage()], $status);
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
     * Void items
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

        DB::beginTransaction();
        try {
            $actionDetails = [];
            $outlet = $order->outlet;
            $warningMessage = null; // Menyiapkan custom message jika diskon jebol minimum

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
                $order->refresh();
                $newSubtotal = $order->items->sum('total_price');

                $newDiscountAmount = 0;

                if ($order->discount_id) {
                    $discountModel = \App\Models\Discount::find($order->discount_id);
                    // VALIDASI THE RULE: Jika subtotal baru kurang dari minimum purchase master diskon
                    if ($discountModel && $newSubtotal < $discountModel->min_purchase) {

                        $warningMessage = 'Refund berhasil diproses. Peringatan: Diskon otomatis dibatalkan karena total belanja kini di bawah batas minimum (Rp ' . number_format($discountModel->min_purchase, 0, ',', '.') . ').';

                        // Cabut atribut diskon paksa
                        $order->update([
                            'discount_id' => null,
                            'manual_discount_type' => null,
                            'manual_discount_value' => null,
                            'discount_amount' => 0,
                        ]);
                        // Refresh agar variabel order state mengikut updatetan hapus diskon
                        $order->refresh();

                    } else {
                        // Jika masih memenuhi syarat, hitung ulang (misal kalau bentuknya persen %)
                        if ($order->manual_discount_type === 'percentage') {
                            $calc = $newSubtotal * ($order->manual_discount_value / 100);
                            if ($discountModel && $discountModel->max_discount && $calc > $discountModel->max_discount) {
                                $calc = $discountModel->max_discount;
                            }
                            $newDiscountAmount = (int) $calc;
                        } else {
                            $oldDiscount = $order->discount_amount ?? 0;
                            $newDiscountAmount = (int) min($oldDiscount, $newSubtotal);
                        }
                    }
                } else {
                    // Jika manual diskon tanpa mengikat master (tidak ada minimal)
                    if ($order->manual_discount_type === 'percentage') {
                        $calc = $newSubtotal * ($order->manual_discount_value / 100);
                        $newDiscountAmount = (int) $calc;
                    } else {
                        $oldDiscount = $order->discount_amount ?? 0;
                        $newDiscountAmount = (int) min($oldDiscount, $newSubtotal);
                    }
                }

                $amountAfterDiscount = max(0, $newSubtotal - $newDiscountAmount);

                $newTaxAmount = 0;
                if ($order->tax_id) {
                    $tax = \App\Models\Tax::find($order->tax_id);
                    if ($tax) {
                        if ($tax->type === 'percentage') {
                            $newTaxAmount = (int) round($amountAfterDiscount * ((float) $tax->rate / 100));
                        } else {
                            $newTaxAmount = (int) $tax->rate;
                        }
                    }
                } elseif ($order->tax_amount > 0 && $order->subtotal_price > 0) {
                    $oldAmountAfterDiscount = max(0, $order->subtotal_price - $order->discount_amount);
                    if ($oldAmountAfterDiscount > 0) {
                        $rate = $order->tax_amount / $oldAmountAfterDiscount;
                        $newTaxAmount = (int) round($amountAfterDiscount * $rate);
                    }
                }

                $order->recalculateTotals([
                    'discount_amount' => $newDiscountAmount,
                    'tax_amount' => $newTaxAmount
                ]);
                // --- END PERBAIKAN ---

                if ($order->status === Order::STATUS_PAID) {
                    $this->orderService->syncHistoryTransaction($order->fresh());
                }
            }

            DB::commit();

            return response()->json([
                // Kembalikan custom warning jika ada, jika aman tampilkan default message
                'message' => $warningMessage ?? 'Void processed',
                'order' => $order->fresh()->load('items.product', 'table', 'user'),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

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
        if (!isset($payload['discount_id'])) {
            $discountSources = [];

            if (!empty($payload['discount_ids']) && is_array($payload['discount_ids'])) {
                $discountSources[] = $payload['discount_ids'];
            }

            if (!empty($payload['discounts']) && is_array($payload['discounts'])) {
                $discountSources[] = $payload['discounts'];
            }

            if (!empty($payload['discount'])) {
                $discountSources[] = [$payload['discount']];
            }

            foreach ($discountSources as $discountSource) {
                foreach ($discountSource as $discountCandidate) {
                    $candidateId = is_array($discountCandidate)
                        ? ($discountCandidate['id'] ?? $discountCandidate['discount_id'] ?? null)
                        : $discountCandidate;

                    if (!empty($candidateId)) {
                        $payload['discount_id'] = (int) $candidateId;
                        break 2;
                    }
                }
            }
        }

        if (isset($payload['discount_id'])) {
            $payload['discount_id'] = (int) $payload['discount_id'];
        }

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
        $order = Order::with('items.product', 'table', 'payments')->where('invoice_number', $request->order_id)->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        $serverKey = $order->midtrans_server_key_used ?: env('MIDTRANS_SERVER_KEY');
        $hashed = hash('sha512', $request->order_id . $request->status_code . $request->gross_amount . $serverKey);

        if ($hashed !== $request->signature_key) {
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        DB::beginTransaction();

        try {
            if (in_array($request->transaction_status, ['settlement', 'capture'], true)) {
                if (in_array($order->payment_method, ['card', 'credit_card'], true)) {
                    $order->payment_method = 'card';
                } elseif (in_array($order->payment_method, ['cash', 'tunai'], true)) {
                    $order->payment_method = 'cash';
                } else {
                    $order->payment_method = 'qris';
                }

                $order->update(['status' => Order::STATUS_PAID]);

                if ($order->table_id) {
                    $order->table->update([
                        'status' => 'reserved',
                        'reserved_until' => now()->addMinutes(20),
                    ]);
                }

                $order->save();
                $this->orderService->syncHistoryTransaction($order->fresh());

                $broadcastOrder = $order->fresh()->load(['items.product', 'table', 'payments']);
                event(new \App\Events\PaymentPaid($broadcastOrder));
                event(new \App\Events\OrderUpdated($broadcastOrder));
            } elseif (in_array($request->transaction_status, ['cancel', 'deny', 'expire'], true)) {
                $order->update(['status' => Order::STATUS_CANCELLED]);

                $outlet = \App\Models\Outlet::find($order->outlet_id);
                if ($outlet) {
                    foreach ($order->items as $item) {
                        $product = $outlet->products()->where('products.id', $item->product_id)->first();

                        if ($product) {
                            $newStock = $product->pivot->stock + $item->qty;
                            $outlet->products()->updateExistingPivot($item->product_id, ['stock' => $newStock]);

                            \App\Models\StockHistory::create([
                                'outlet_id' => $order->outlet_id,
                                'product_id' => $item->product_id,
                                'user_id' => null,
                                'type' => 'restore',
                                'quantity' => $item->qty,
                                'final_stock' => $newStock,
                                'reference' => 'Auto-Cancel Midtrans: ' . $order->invoice_number,
                            ]);
                        }
                    }
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('Midtrans callback error: ' . $e->getMessage());
        }

        return response()->json(['message' => 'Callback received']);
    }
}
