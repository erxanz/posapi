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
        $order = Order::with(['items.product', 'table'])->findOrFail($id);

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

        $query = Order::with(['items.product', 'table', 'user', 'outlet']);

        // Untuk kebutuhan Flutter: saat pending, UI perlu tahu payment method.
        // Sekarang payment_method diambil dari kolom orders (terisi sejak order dibuat).
        // Namun tetap eager-load payments sebagai fallback untuk data lama.
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
        }

        $limit = $request->input('limit', 10);

        $paginator = $query->latest()->paginate($limit);

        // Map field agar kompatibel dengan kebutuhan Flutter: payment_method.
        // Ambil payment method dari payment pertama (paling awal) jika ada.
            $paginator->getCollection()->transform(function (Order $order) {
            // Prioritas: ambil dari kolom orders.payment_method yang sudah terisi saat order dibuat.
            $paymentMethod = $order->payment_method;

            // Fallback untuk data lama (kolom null) agar tetap kompatibel.
            if (empty($paymentMethod) && $order->relationLoaded('payments') && $order->payments) {
                $payment = $order->payments->sortBy(fn($p) => $p->paid_at ?? $p->created_at)->first();
                $paymentMethod = $payment?->method;
            }

            $order->payment_method = $paymentMethod;

            return $order;
        });

        return response()->json($paginator);
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
        // TAMBAHAN BARU: KEMBALIKAN STOK KE GUDANG
        $outlet = \App\Models\Outlet::find($order->outlet_id);
        $product = $outlet->products()->where('products.id', $item->product_id)->first();

        if ($product) {
            // Tambahkan kembali qty yang dibatalkan ke stok saat ini
            $newStock = $product->pivot->stock + $item->qty;
            $outlet->products()->updateExistingPivot($item->product_id, ['stock' => $newStock]);

            // Catat riwayat pengembalian stok
            \App\Models\StockHistory::create([
                'outlet_id' => $order->outlet_id,
                'product_id' => $item->product_id,
                'user_id' => auth()->id(), // ID kasir yang menghapus
                'type' => 'restore',
                'quantity' => $item->qty, // Qty yang kembali (positif)
                'final_stock' => $newStock,
                'reference' => 'Remove Item: ' . $order->invoice_number,
            ]);
        }

        // Hapus item dari keranjang
        $item->delete();

        // Hitung ulang subtotal, pajak, dan diskon
        $this->updateTotal($order);

        return response()->json($order->load('items.product'));
    }

    /**
     * Cancel a single item (partial or full) safely inside a DB transaction.
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

            $diff = $locked->cancelled_qty - $oldCancelled; // positive when cancelled

            // Adjust stock back to outlet product pivot if outlet exists
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

            // Append order log (reason optional)
            $logs = $order->logs ?? [];
            array_unshift($logs, [
                'date' => now()->format('d M Y H:i'),
                'action' => "Cancel {$diff}x " . ($locked->product->name ?? 'item'),
                'reason' => $request->input('reason') ?? null,
                'by' => auth()->user()->name ?? 'system',
            ]);
            $order->update(['logs' => $logs]);

            // Recalculate totals from backend authoritative source
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
            // Tambahkan validasi payment_method biar tertangkap
            'payment_method' => 'required|string|max:50',

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
            // 1. Buat pesanan mentahnya dulu
            $result = $this->orderService->createPublicOrder($validated);

            // Ambil object order dari result (sesuaikan jika orderService return array atau object)
            $order = is_array($result) ? ($result['order'] ?? null) : $result;
            if (!$order && is_array($result) && isset($result['id'])) {
                $order = Order::find($result['id']);
            }

            // Reload order biar datanya fresh beserta relasinya
            $order = Order::with('items.product', 'table')->findOrFail($order->id);

            // 2. Cek apakah user milih bayar online (Midtrans / Qris)
            $methodStr = strtolower($request->payment_method);
            if (in_array($methodStr, ['midtrans', 'qris', 'card'])) {

                // Gunakan server key berdasarkan outlet owner (multi-tenant)
                $serverKey = $order->midtrans_server_key_used;

                // Fallback jika field belum terisi (mis. order dibuat versi lama)
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
                $calculatedGrossAmount = 0;

                foreach ($order->items as $item) {
                    $itemPrice = (int) $item->price;
                    $itemQty = (int) $item->qty;

                    $itemDetails[] = [
                        'id' => (string) $item->product_id,
                        'name' => substr($item->product->name, 0, 50),
                        'price' => $itemPrice,
                        'quantity' => $itemQty,
                    ];
                    $calculatedGrossAmount += ($itemPrice * $itemQty);
                }

                // Kalkulasi Diskon untuk Midtrans
                $discountAmount = (int) ($order->discount_amount ?? 0);
                if ($discountAmount > 0) {
                    $itemDetails[] = [
                        'id' => 'DISCOUNT',
                        'name' => 'Discount',
                        'price' => -$discountAmount,
                        'quantity' => 1,
                    ];
                    $calculatedGrossAmount -= $discountAmount;
                }

                // Kalkulasi Pajak untuk Midtrans
                $taxAmount = (int) ($order->tax_amount ?? 0);
                if ($taxAmount > 0) {
                    $itemDetails[] = [
                        'id' => 'TAX',
                        'name' => 'Tax',
                        'price' => $taxAmount,
                        'quantity' => 1,
                    ];
                    $calculatedGrossAmount += $taxAmount;
                }

                $params = [
                    'transaction_details' => [
                        'order_id' => $order->invoice_number,
                        'gross_amount' => $calculatedGrossAmount,
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
                    $params['enabled_payments'] = ['gopay', 'other_qris'];
                } elseif ($methodStr === 'card') {
                    $params['enabled_payments'] = ['credit_card'];
                }

                $paymentUrl = \Midtrans\Snap::createTransaction($params)->redirect_url;

                // Return format yang persis sama dengan yang ditunggu cart.vue
                return response()->json([
                    'success' => true,
                    'message' => 'Order berhasil dibuat',
                    'data' => [
                        'order' => $order,
                        'redirect_url' => $paymentUrl
                    ]
                ], 201);
            }

            // 3. Jika bayar Cash
            // Catatan: untuk QR flow, meja akan jadi reserved (menunggu) berdasarkan timer,
            // bukan langsung available.
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

                // Multi MIDTRANS key: jika role manager dan manager isi server key sendiri,
                // maka transaksi Midtrans-nya memakai merchant manager tersebut.
                if ($user && $user->role === 'manager') {
                    if (empty($user->midtrans_server_key)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Midtrans server key manager belum diisi',
                        ], 403);
                    }
                    Config::$serverKey = $user->midtrans_server_key;
                }


                $itemDetails = [];
                $calculatedGrossAmount = 0; // KUNCI: Hitung total berbarengan dengan build array

                foreach ($order->items as $item) {
                    $itemPrice = (int) $item->price;
                    $itemQty = (int) $item->qty;

                    $itemDetails[] = [
                        'id' => (string) $item->product_id,
                        'name' => substr($item->product->name, 0, 50),
                        'price' => $itemPrice,
                        'quantity' => $itemQty,
                    ];

                    $calculatedGrossAmount += ($itemPrice * $itemQty);
                }

                /**
                 * DISKON MASUK MIDTRANS
                 */
                $discountAmount = (int) ($order->discount_amount ?? 0);
                if ($discountAmount > 0) {
                    $itemDetails[] = [
                        'id' => 'DISCOUNT',
                        'name' => 'Discount',
                        'price' => -$discountAmount, // Minus harga
                        'quantity' => 1,
                    ];
                    // Kurangi total tagihan
                    $calculatedGrossAmount -= $discountAmount;
                }

                /**
                 * PAJAK MASUK MIDTRANS
                 */
                $taxAmount = (int) ($order->tax_amount ?? 0);
                if ($taxAmount > 0) {
                    $itemDetails[] = [
                        'id' => 'TAX',
                        'name' => 'Tax',
                        'price' => $taxAmount, // Tambah harga
                        'quantity' => 1,
                    ];
                    // Tambah ke total tagihan
                    $calculatedGrossAmount += $taxAmount;
                }

                $params = [
                    'transaction_details' => [
                        'order_id' => $order->invoice_number,
                        'gross_amount' => $calculatedGrossAmount,
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

                // ========================================================
                // FITUR BARU: SKIP HALAMAN PEMILIHAN MIDTRANS
                // Jika array enabled_payments isinya HANYA 1, Midtrans akan otomatis
                // lompat (direct) ke halaman barcode QRIS atau form Kartu
                // ========================================================
                $methodStr = strtolower($validated['payment_method'] ?? '');

                $user = $user ?? auth()->user();
                if ($user && $user->role === 'manager') {
                    if (empty($user->midtrans_server_key)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Midtrans server key manager belum diisi',
                        ], 403);
                    }
                    Config::$serverKey = $user->midtrans_server_key;
                }

                if ($methodStr === 'qris') {

                    // 'gopay' di Midtrans secara default memunculkan barcode QRIS dinamis
                    // yang bisa di-scan oleh semua dompet digital (ShopeePay, Dana, OVO, m-banking dll)
                    $params['enabled_payments'] = ['gopay'];

                    // Catatan: Jika di dashboard lu 'gopay' belum diaktifkan, ubah array di atas menjadi ['other_qris']
                } elseif ($methodStr === 'card' || $methodStr === 'credit_card') {
                    $params['enabled_payments'] = ['credit_card'];
                }

                // Terakhir, eksekusi pemanggilan Midtrans Snap
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
     * Void items (DENGAN VALIDASI MINIMUM DISKON)
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
        // Gunakan server key yang dipakai saat create transaksi (disimpan di orders)
        $order = Order::where('invoice_number', $request->order_id)->first();
        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        $serverKey = $order->midtrans_server_key_used ?: env('MIDTRANS_SERVER_KEY');

        $hashed = hash("sha512", $request->order_id . $request->status_code . $request->gross_amount . $serverKey);


        // Verifikasi kalau request beneran dari Midtrans
        if ($hashed == $request->signature_key) {
            if ($order) {

                DB::beginTransaction();

                try {
                    if ($request->transaction_status == 'capture' || $request->transaction_status == 'settlement') {
                        // Pembayaran sukses
                        $order->update(['status' => Order::STATUS_PAID]);

                        $paymentMethod = $request->payment_type ?? 'midtrans';

                        // Buat payment record
                        $createdPayment = \App\Models\Payment::create([
                            'order_id' => $order->id,
                            'amount_paid' => (int) $request->gross_amount,
                            'change_amount' => 0,
                            'method' => $paymentMethod,
                            'reference_no' => $request->transaction_id ?? null,
                            'paid_at' => now(),
                            'paid_by' => null,
                        ]);

                        // Sync payment method ke orders agar UI tahu metode pembayaran.
                        $order->payment_method = strtolower(trim($createdPayment->method ?? 'midtrans'));
                        if (in_array($order->payment_method, ['qris', 'midtrans'])) {
                            $order->payment_method = 'qris';
                        } elseif (in_array($order->payment_method, ['card', 'credit_card'])) {
                            $order->payment_method = 'card';
                        } elseif (in_array($order->payment_method, ['cash', 'tunai'])) {
                            $order->payment_method = 'cash';
                        }
                        $order->save();


                        // Update table status
                        // Untuk flow QR: meja tidak langsung available.
                        // Timer reserved_until akan mengembalikan meja otomatis.
                        // Jadi di sini cukup set reserved (jika belum ada).
                        if ($order->table_id) {
                            $order->table->update([
                                'status' => 'reserved',
                                'reserved_until' => now()->addMinutes(20),
                            ]);
                        }


                        // Store history transaction
                        $this->orderService->syncHistoryTransaction($order->fresh());

                        // Trigger event agar frontend tahu ada perubahan
                        $broadcastOrder = $order->fresh()->load(['items.product', 'table', 'payments']);

                        // Event PaymentPaid supaya POS kasir mobile bisa trigger print via listener yang sama seperti flow cash
                        event(new \App\Events\PaymentPaid($broadcastOrder));
                        event(new \App\Events\OrderUpdated($broadcastOrder));

                    } else if ($request->transaction_status == 'cancel' || $request->transaction_status == 'deny' || $request->transaction_status == 'expire') {

                        // 1. Ubah status pesanan
                        $order->update(['status' => Order::STATUS_CANCELLED]);

                        // 2. KEMBALIKAN STOK
                        $outlet = \App\Models\Outlet::find($order->outlet_id);

                        foreach ($order->items as $item) {
                            $product = $outlet->products()->where('products.id', $item->product_id)->first();

                            if ($product) {
                                $newStock = $product->pivot->stock + $item->qty;
                                $outlet->products()->updateExistingPivot($item->product_id, ['stock' => $newStock]);

                                // Catat di riwayat bahwa stok dikembalikan oleh sistem otomatis
                                \App\Models\StockHistory::create([
                                    'outlet_id' => $order->outlet_id,
                                    'product_id' => $item->product_id,
                                    'user_id' => null, // null karena sistem yang mengeksekusi
                                    'type' => 'restore',
                                    'quantity' => $item->qty,
                                    'final_stock' => $newStock,
                                    'reference' => 'Auto-Cancel Midtrans: ' . $order->invoice_number,
                                ]);
                            }
                        }
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
