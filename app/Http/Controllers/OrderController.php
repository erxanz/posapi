<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Outlet;
use App\Models\Tax;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Http\Requests\CancelOrderItemRequest;
use App\Models\OrderItem;
use App\Models\Payment;
use Midtrans\Config;
use Midtrans\Snap;

class OrderController extends Controller
{
    public function __construct(private OrderService $orderService) {
        $this->middleware('auth:sanctum')->except(['publicOrder', 'midtransCallback', 'publicShow', 'downloadQrImage']);
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
     * Proxy unduh gambar QRIS Midtrans sebagai attachment.
     *
     * Browser tidak bisa fetch/canvas gambar QR Midtrans langsung karena endpoint
     * QR-nya tidak mengirim header CORS. Endpoint ini mengambil gambar server-side
     * lalu mengirim balik dengan Content-Disposition: attachment supaya pelanggan
     * bisa langsung mengunduh fotonya (bukan dibuka di tab baru).
     *
     * Host di-whitelist ke domain Midtrans saja untuk mencegah SSRF.
     */
    public function downloadQrImage(Request $request)
    {
        $url = (string) $request->query('url', '');
        if ($url === '') {
            abort(400, 'Parameter url wajib diisi.');
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $allowedHosts = ['api.midtrans.com', 'api.sandbox.midtrans.com'];
        if (!in_array($host, $allowedHosts, true)) {
            abort(403, 'Host tidak diizinkan.');
        }

        $response = Http::timeout(15)->get($url);
        if (!$response->successful()) {
            abort(502, 'Gagal mengambil gambar QR dari Midtrans.');
        }

        $contentType = $response->header('Content-Type') ?: 'image/png';
        // Pastikan yang diunduh memang gambar, bukan envelope error JSON.
        if (stripos($contentType, 'image/') !== 0) {
            abort(502, 'Respons Midtrans bukan gambar QR.');
        }

        return response($response->body(), 200)
            ->header('Content-Type', $contentType)
            ->header('Content-Disposition', 'attachment; filename="QR-Pembayaran.png"');
    }

    /**
     * List order
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        $query = Order::with(['items.product', 'table', 'user', 'outlet', 'latestAcceptance']);

        if ($request->filled('status')) {
            if ($request->status === Order::STATUS_PENDING) {
                $query->where(function ($q) {
                    $q->where('status', Order::STATUS_PENDING)
                        ->orWhere(function ($subQ) {
                            $subQ->where('status', Order::STATUS_PAID)
                               ->whereNull('user_id')
                               ->where('payment_method', '!=', 'cash')
                               ->doesntHave('latestAcceptance');
                        });
                });
            } else {
                $query->where('status', $request->status);
            }
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
            'qty' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $order = $this->orderService->addItemToOrder(
                (int) $orderId,
                (int) $request->product_id,
                (int) $request->qty,
                $request->notes
            );

            event(new \App\Events\OrderUpdated($order));

            return response()->json($order, 200);
        } catch (\Throwable $e) {
            $status = $e instanceof \RuntimeException ? 400 : 500;
            return response()->json(['message' => $e->getMessage()], $status);
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

    public function show(Order $order)
    {
        if (!$this->canAccessOrder($order)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(
            $order->load('items.product', 'table', 'payments', 'latestAcceptance', 'discount')
        );
    }

    private function canAccessOrder(Order $order): bool
    {
        $user = auth()->user();
        if ($user->role === 'developer') return true;
        if ($user->role === 'manager') return \App\Models\Outlet::where('id', $order->outlet_id)->where('owner_id', $user->id)->exists();
        return (int) $user->outlet_id === (int) $order->outlet_id;
    }

    /**
     * Hapus item dari order pending
     */
    public function removeItem($orderId, $itemId)
    {
        try {
            $order = $this->orderService->removeItemFromOrder((int) $orderId, (int) $itemId);

            event(new \App\Events\OrderUpdated($order));

            return response()->json($order, 200);
        } catch (\Throwable $e) {
            $status = $e instanceof \RuntimeException ? 400 : 500;
            return response()->json(['message' => $e->getMessage()], $status);
        }
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

        try {
            $updatedOrder = $this->orderService->cancelOrderItem(
                $order,
                $item,
                (int) $request->input('cancel_qty'),
                $request->input('reason')
            );

            event(new \App\Events\OrderUpdated($updatedOrder));

            return response()->json([
                'message' => 'Item cancelled',
                'order' => $updatedOrder,
            ]);
        } catch (\Throwable $e) {
            $status = $e instanceof \RuntimeException ? 400 : 500;
            return response()->json(['message' => $e->getMessage()], $status);
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
            'discount_id' => 'nullable|exists:discounts,id',
            'discount_ids' => 'nullable|array',
            'discount_ids.*' => 'exists:discounts,id',
            'discount_type' => 'nullable|in:percentage,nominal',
            'discount_value' => 'nullable|integer|min:0',
            'tax_id' => 'nullable|exists:taxes,id',
            'tax_type' => 'nullable|in:percentage,fixed',
            'tax_value' => 'nullable|integer|min:0',
            'tax_amount' => 'nullable|integer|min:0',
            'tax_breakdown' => 'nullable|array',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.price' => 'nullable|integer|min:1',
            'items.*.notes' => 'nullable|string|max:500',
            'previous_order_id' => 'nullable',
        ]);

        if (!empty($validated['previous_order_id'])) {
            $oldOrder = \App\Models\Order::where('id', $validated['previous_order_id'])
                ->where('status', 'pending')
                ->where('table_id', $validated['table_id'])
                ->first();

            if ($oldOrder) {
                $oldOrder->loadMissing('items');
                $outletForRestock = \App\Models\Outlet::find($oldOrder->outlet_id);

                if ($outletForRestock) {
                    foreach ($oldOrder->items as $item) {
                        $product = $outletForRestock->products()->where('products.id', $item->product_id)->first();
                        if ($product) {
                            $newStock = $product->pivot->stock + $item->qty;
                            $outletForRestock->products()->updateExistingPivot($item->product_id, ['stock' => $newStock]);

                            \App\Models\StockHistory::create([
                                'outlet_id' => $oldOrder->outlet_id,
                                'product_id' => $item->product_id,
                                'user_id' => null,
                                'type' => 'restore',
                                'quantity' => $item->qty,
                                'final_stock' => $newStock,
                                'reference' => 'Auto-Cancel Previous Order: ' . $oldOrder->invoice_number,
                            ]);
                        }
                    }
                }

                $oldOrder->decrementDiscountUsage();
                $oldOrder->update(['status' => 'cancelled']);

                event(new \App\Events\OrderUpdated($oldOrder));
            }
        }

        $validated = $this->normalizeLegacyAdjustmentPayload($validated);

        try {
            $result = $this->orderService->createPublicOrder($validated);

            $order = is_array($result) ? ($result['order'] ?? null) : $result;
            if (!$order && is_array($result) && isset($result['id'])) {
                $order = Order::find($result['id']);
            }

            // Force load dengan relasi discount agar data diskon terbaca
            $order = Order::with(['items.product', 'table', 'discount'])->findOrFail($order->id);

            // KUNCI UTAMA: Hitung total hanya sekali (sudah dipanggil di Service)
            // $order->recalculateTotals($validated); // dihapus
            $order->refresh();

            $methodStr = strtolower($request->payment_method);
            if (in_array($methodStr, ['midtrans', 'qris', 'card', 'credit_card'])) {

                $serverKey = $order->midtrans_server_key_used;
                if (empty($serverKey) && $order->outlet_id) {
                    $ownerId = \App\Models\Outlet::whereKey($order->outlet_id)->value('owner_id');
                    $serverKey = \App\Models\User::whereKey($ownerId)->value('midtrans_server_key');
                }

                // PENTING: jangan fallback diam-diam ke MIDTRANS_SERVER_KEY global.
                // Sistem ini multi-tenant (server key per owner outlet) - kalau
                // owner belum setup server key sendiri, memaksakan pakai key
                // global bisa membuat pembayaran customer masuk ke akun Midtrans
                // yang salah merchant. Lebih aman menolak transaksi online di sini
                // daripada memproses pembayaran ke rekening yang salah.
                if (empty($serverKey)) {
                    $order->loadMissing('items');
                    $outletForRestock = \App\Models\Outlet::find($order->outlet_id);
                    if ($outletForRestock) {
                        foreach ($order->items as $item) {
                            $product = $outletForRestock->products()->where('products.id', $item->product_id)->first();
                            if ($product) {
                                $newStock = $product->pivot->stock + $item->qty;
                                $outletForRestock->products()->updateExistingPivot($item->product_id, ['stock' => $newStock]);

                                \App\Models\StockHistory::create([
                                    'outlet_id' => $order->outlet_id,
                                    'product_id' => $item->product_id,
                                    'user_id' => null,
                                    'type' => 'restore',
                                    'quantity' => $item->qty,
                                    'final_stock' => $newStock,
                                    'reference' => 'Auto-Cancel (Server Key Belum Diatur): ' . $order->invoice_number,
                                ]);
                            }
                        }
                    }

                    $order->update(['status' => Order::STATUS_CANCELLED]);
                    event(new \App\Events\OrderUpdated($order->fresh()->load('items.product', 'table')));
                    return response()->json([
                        'message' => 'Outlet ini belum mengaktifkan pembayaran online. Silakan hubungi pengelola outlet atau gunakan metode pembayaran cash.'
                    ], 422);
                }

                \Midtrans\Config::$serverKey = $serverKey;
                \Midtrans\Config::$isProduction = env('MIDTRANS_IS_PRODUCTION', false);
                \Midtrans\Config::$isSanitized = true;
                \Midtrans\Config::$is3ds = true;

                $itemDetails = [];
                $calculatedGrossAmount = 0; // Tambahkan penampung hitungan manual

                // 1. Masukkan Item Produk
                foreach ($order->items as $item) {
                    $itemPrice = (int) $item->price;
                    $itemQty = (int) $item->qty;
                    $itemTotalPrice = $itemPrice * $itemQty;

                    $itemDetails[] = [
                        'id' => (string) $item->product_id,
                        'name' => substr($item->product->name, 0, 50),
                        'price' => $itemPrice,
                        'quantity' => $itemQty,
                    ];

                    $calculatedGrossAmount += $itemTotalPrice; // Tambah subtotal item
                }

                // 2. Masukkan Potongan Diskon (Bernilai Negatif)
                $discountAmount = (int) ($order->discount_amount ?? 0);
                if ($discountAmount > 0) {
                    $itemDetails[] = [
                        'id' => 'DISCOUNT',
                        'name' => 'Discount Potongan Harga',
                        'price' => -$discountAmount, // WAJIB NEGATIF
                        'quantity' => 1,
                    ];

                    $calculatedGrossAmount -= $discountAmount; // Kurangi total belanja dengan diskon
                }

                // 3. Masukkan Biaya Pajak (Jika ada)
                $taxAmount = (int) ($order->tax_amount ?? 0);
                if ($taxAmount > 0) {
                    $itemDetails[] = [
                        'id' => 'TAX',
                        'name' => 'Tax / Pajak',
                        'price' => $taxAmount,
                        'quantity' => 1,
                    ];

                    $calculatedGrossAmount += $taxAmount; // Tambah total belanja dengan pajak
                }

                // KUNCI UTAMA: Gunakan $calculatedGrossAmount agar sinkron dengan $itemDetails
                // Serta berikan fallback ke $order->total_price jika karena alasan tertentu hitungannya <= 0
                $finalGrossAmount = $calculatedGrossAmount > 0 ? $calculatedGrossAmount : (int) $order->total_price;

                $params = [
                    'transaction_details' => [
                        'order_id' => $order->invoice_number,
                        'gross_amount' => $finalGrossAmount, // Total bayar akhir setelah diskon & pajak
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

                if ($methodStr === 'qris') {
                    $params = [
                        'payment_type' => 'qris',
                        'transaction_details' => [
                            'order_id' => $order->invoice_number,
                            'gross_amount' => $finalGrossAmount,
                        ],
                        'customer_details' => [
                            'first_name' => $order->customer_name ?: 'Customer POS',
                        ],
                        'item_details' => $itemDetails,
                        'qris' => [
                            'acquirer' => 'gopay', // opsional, bisa dilepas
                        ],
                    ];

                    $charge = \Midtrans\CoreApi::charge($params);

                    $qrUrl = null;
                    foreach ($charge->actions as $action) {
                        if (in_array($action->name, ['generate-qr-code-v2', 'generate-qr-code'])) {
                            $qrUrl = $action->url;
                            break;
                        }
                    }

                    return response()->json([
                        'success' => true,
                        'message' => 'Order berhasil dibuat',
                        'data' => [
                            'order' => $order->load('items.product', 'table'),
                            'qr_url' => $qrUrl,
                            'transaction_id' => $charge->transaction_id,
                            'transaction_status' => $charge->transaction_status,
                            'expiry_time' => $charge->expiry_time ?? null,
                        ]
                    ], 201);
                }

                if (in_array($methodStr, ['card', 'credit_card', 'midtrans'])) {
                    if ($methodStr === 'card' || $methodStr === 'credit_card') {
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

                return response()->json([
                    'message' => 'Metode pembayaran tidak dikenali'
                ], 400);
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
            'discount_amount' => 'nullable|integer|min:0',
            'tax_id' => 'nullable|exists:taxes,id',
            'tax_type' => 'nullable|in:percentage,fixed',
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

                $serverKey = $order->midtrans_server_key_used;
                if (empty($serverKey) && $order->outlet_id) {
                    $ownerId = \App\Models\Outlet::whereKey($order->outlet_id)->value('owner_id');
                    $serverKey = \App\Models\User::whereKey($ownerId)->value('midtrans_server_key');
                }

                // PENTING: jangan fallback diam-diam ke MIDTRANS_SERVER_KEY global, dan
                // jangan berbasis role user yang sedang checkout (karyawan vs manager) -
                // server key yang dipakai harus selalu milik owner outlet tersebut.
                // Lihat catatan yang sama di publicOrder().
                if (empty($serverKey)) {
                    $order->loadMissing('items');
                    $outletForRestock = \App\Models\Outlet::find($order->outlet_id);
                    if ($outletForRestock) {
                        foreach ($order->items as $item) {
                            $product = $outletForRestock->products()->where('products.id', $item->product_id)->first();
                            if ($product) {
                                $newStock = $product->pivot->stock + $item->qty;
                                $outletForRestock->products()->updateExistingPivot($item->product_id, ['stock' => $newStock]);

                                \App\Models\StockHistory::create([
                                    'outlet_id' => $order->outlet_id,
                                    'product_id' => $item->product_id,
                                    'user_id' => null,
                                    'type' => 'restore',
                                    'quantity' => $item->qty,
                                    'final_stock' => $newStock,
                                    'reference' => 'Auto-Cancel (Server Key Belum Diatur): ' . $order->invoice_number,
                                ]);
                            }
                        }
                    }

                    $order->update(['status' => Order::STATUS_CANCELLED]);
                    event(new \App\Events\OrderUpdated($order->fresh()->load('items.product', 'table')));
                    return response()->json([
                        'message' => 'Outlet ini belum mengaktifkan pembayaran online. Silakan hubungi pengelola outlet atau gunakan metode pembayaran cash.'
                    ], 422);
                }

                Config::$serverKey = $serverKey;
                Config::$isProduction = env('MIDTRANS_IS_PRODUCTION', false);
                Config::$isSanitized = true;
                Config::$is3ds = true;

                $itemDetails = [];
                $calculatedGrossAmount = 0; // Tambahkan penampung hitungan manual

                // 1. Masukkan Item Produk
                foreach ($order->items as $item) {
                    $itemPrice = (int) $item->price;
                    $itemQty = (int) $item->qty;
                    $itemTotalPrice = $itemPrice * $itemQty;

                    $itemDetails[] = [
                        'id' => (string) $item->product_id,
                        'name' => substr($item->product->name, 0, 50),
                        'price' => $itemPrice,
                        'quantity' => $itemQty,
                    ];

                    $calculatedGrossAmount += $itemTotalPrice; // Tambah subtotal item
                }

                // 2. Masukkan Diskon
                $discountAmount = (int) ($order->discount_amount ?? 0);
                if ($discountAmount > 0) {
                    $itemDetails[] = [
                        'id' => 'DISCOUNT',
                        'name' => 'Discount',
                        'price' => -$discountAmount,
                        'quantity' => 1,
                    ];
                    $calculatedGrossAmount -= $discountAmount; // Kurangi total dari diskon
                }

                // 3. Masukkan Pajak
                $taxAmount = (int) ($order->tax_amount ?? 0);
                if ($taxAmount > 0) {
                    $itemDetails[] = [
                        'id' => 'TAX',
                        'name' => 'Tax',
                        'price' => $taxAmount,
                        'quantity' => 1,
                    ];
                    $calculatedGrossAmount += $taxAmount; // Tambah total dari pajak
                }

                // KUNCI UTAMA: Gunakan calculated amount
                $finalGrossAmount = $calculatedGrossAmount > 0 ? $calculatedGrossAmount : (int) $order->total_price;

                $params = [
                    'transaction_details' => [
                        'order_id' => $order->invoice_number,
                        'gross_amount' => $finalGrossAmount, // <-- Panggil nilai kalkulasi akhir
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

                if ($methodStr === 'qris') {
                    $params = [
                        'payment_type' => 'qris',
                        'transaction_details' => [
                            'order_id' => $order->invoice_number,
                            'gross_amount' => $finalGrossAmount,
                        ],
                        'customer_details' => [
                            'first_name' => $order->customer_name ?: 'Customer POS',
                        ],
                        'item_details' => $itemDetails,
                        'qris' => [
                            'acquirer' => 'gopay', // opsional, bisa dilepas
                        ],
                    ];

                    $charge = \Midtrans\CoreApi::charge($params);

                    $qrUrl = null;
                    foreach ($charge->actions as $action) {
                        if (in_array($action->name, ['generate-qr-code-v2', 'generate-qr-code'])) {
                            $qrUrl = $action->url;
                            break;
                        }
                    }

                    return response()->json([
                        'success' => true,
                        'message' => 'Order berhasil dibuat',
                        'data' => [
                            'order' => $order->load('items.product', 'table'),
                            'qr_url' => $qrUrl,
                            'transaction_id' => $charge->transaction_id,
                            'transaction_status' => $charge->transaction_status,
                            'expiry_time' => $charge->expiry_time ?? null,
                        ]
                    ], 201);
                }

                if (in_array($methodStr, ['card', 'credit_card', 'midtrans'])) {
                    // sisanya pakai Snap seperti sebelumnya (card butuh Snap/Midtrans.js karena perlu card token)
                    if ($methodStr === 'card' || $methodStr === 'credit_card') {
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

                return response()->json([
                    'message' => 'Metode pembayaran tidak dikenali'
                ], 400);
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
        // Konsisten dengan voidItems/updateItems/cancelItem: pastikan user
        // hanya bisa mengubah diskon/pajak order milik outlet-nya sendiri.
        // Tanpa ini, user mana pun yang tahu id order bisa mengedit adjustment
        // order outlet/tenant lain.
        $this->authorizeOutletAccess($order);

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
            'tax_type' => 'nullable|in:percentage,fixed',
            'tax_value' => 'nullable|integer|min:0',
            'tax_amount' => 'nullable|integer|min:0',
            'tax_breakdown' => 'nullable|array',
        ]);

        $validated = $this->normalizeLegacyAdjustmentPayload($validated);

        $order->update($validated);
        $order->recalculateTotals($validated);

        event(new \App\Events\OrderUpdated($order->fresh()->load('items.product', 'table')));

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
            'items.*.cancelled_qty' => 'required|integer|min:0',
            'tax_amount' => 'nullable|numeric',
            'total_price' => 'nullable|numeric',
            'discount_amount' => 'nullable|numeric',
            'subtotal_price' => 'nullable|numeric',
        ]);

        $overrideTotals = [
            'tax_amount' => $validated['tax_amount'] ?? null,
            'total_price' => $validated['total_price'] ?? null,
            'discount_amount' => $validated['discount_amount'] ?? null,
            'subtotal_price' => $validated['subtotal_price'] ?? null,
        ];

        try {
            $result = $this->orderService->voidOrderItems($order, $validated['reason'], $validated['items'], $overrideTotals);

            event(new \App\Events\OrderUpdated($result['order']));

            return response()->json([
                'message' => $result['warning'] ?? 'Void processed',
                'order' => $result['order'],
            ]);
        } catch (\Throwable $e) {
            $status = $e instanceof \RuntimeException ? 422 : 500;
            return response()->json(['message' => $e->getMessage()], $status);
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
            'items.*.notes' => 'nullable|string|max:500',
        ]);

        try {
            $order = $this->orderService->updateOrderItems($order, $validated['items']);

            event(new \App\Events\OrderUpdated($order));

            return response()->json([
                'message' => 'Order items updated',
                'order' => $order,
            ]);
        } catch (\Throwable $e) {
            $status = $e instanceof \RuntimeException ? 400 : 500;
            return response()->json(['message' => $e->getMessage()], $status);
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
        if ($user->role === 'developer') return;
        if ($user->role === 'manager') {
            if (!\App\Models\Outlet::where('id', $order->outlet_id)->where('owner_id', $user->id)->exists()) {
                abort(403);
            }
            return;
        }
        if ((int) $user->outlet_id !== (int) $order->outlet_id) {
            abort(403);
        }
    }

    private function normalizeLegacyAdjustmentPayload(array $payload): array
    {
        // Diskon bertumpuk (produk/kategori): kalau client mengirim lebih dari satu
        // discount_ids, JANGAN dikecilkan jadi discount_id tunggal di sini. Biarkan
        // array-nya utuh supaya OrderService::handleAdjustments menghitung gabungan
        // semua diskon (per-item terbaik). discount_id tunggal cuma untuk 1 diskon.
        $multipleDiscountIds = !empty($payload['discount_ids'])
            && is_array($payload['discount_ids'])
            && count($payload['discount_ids']) > 1;

        if (!isset($payload['discount_id']) && !$multipleDiscountIds) {
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
            $taxQuery = Tax::query()
                ->where('type', $payload['tax_type'])
                ->where('active', true);

            if (!empty($payload['outlet_id'])) {
                $taxQuery->where('outlet_id', $payload['outlet_id']);
            }

            $tax = $taxQuery->get()
                ->first(function (Tax $tax) use ($payload) {
                    $expectedValue = (int) round((float) $tax->rate);

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
        try {
            $result = $this->orderService->handleMidtransCallback($request->all());

            if ($result['status'] === 404) {
                return response()->json(['message' => $result['message']], 404);
            }

            if ($result['status'] === 403) {
                return response()->json(['message' => $result['message']], 403);
            }

            return response()->json(['message' => $result['message']], $result['status']);
        } catch (\Throwable $e) {
            \Log::error('Midtrans callback error: ' . $e->getMessage());
            return response()->json(['message' => 'Internal error'], 500);
        }
    }
}
