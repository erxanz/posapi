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

        if ($request->filled('status')) {
            if ($request->status === Order::STATUS_PENDING) {
                // MODIFIKASI: Tarik data PENDING + PAID (yang belum di-ACC Kasir)
                $query->where(function ($q) {
                    // 1. Tarik semua pesanan yang memang masih murni PENDING
                    $q->where('status', Order::STATUS_PENDING)
                      ->orWhere(function ($subQ) {
                          // 2. ATAU tarik pesanan yang sudah LUNAS, dengan syarat:
                          $subQ->where('status', Order::STATUS_PAID)
                               ->whereNull('user_id') // Harus murni dari POS QR (Bukan kasir)
                               ->where('payment_method', '!=', 'cash') // Hanya untuk pembayaran online/Midtrans
                               ->doesntHave('latestAcceptance'); // Belum ditekan tombol "Terima" (Accept) oleh Kasir
                      });
                });
            } else {
                // Untuk status lain (seperti paid/cancelled/dsb), filter normal seperti biasa
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

        if ($request->filled('status')) {
            // $query->where('status', $request->status);

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
            'qty' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:500',
        ]);

        // Ambil order berdasarkan outlet kasir yang sedang login
        $order = Order::where('id', $orderId)
            ->where('outlet_id', auth()->user()->outlet_id)
            ->firstOrFail();

        // Izinkan edit jika status masih pending (belum dibayar lunas)
        if ($order->status !== Order::STATUS_PENDING && $order->status !== 'pending') {
            return response()->json(['message' => 'Order sudah diproses/lunas dan tidak bisa diubah'], 400);
        }

        $requestQty = (int) $request->qty;

        DB::beginTransaction();
        try {
            // Kunci baris produk SEBELUM membaca stok, supaya 2 request
            // bersamaan untuk produk yang sama tidak bisa lolos validasi
            // stok berdasarkan nilai yang sama-sama sudah usang (overselling).
            $product = Outlet::query()
                ->findOrFail($order->outlet_id)
                ->products()
                ->where('products.id', $request->product_id)
                ->wherePivot('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();

            $availableStock = (int) $product->pivot->stock;

            if ($requestQty > $availableStock) {
                DB::rollBack();
                return response()->json([
                    'message' => "Stok {$product->name} tidak cukup (maksimal {$availableStock})"
                ], 400);
            }

            $price = (int) $product->pivot->price;

            $item = $order->items()->where('product_id', $product->id)->first();

            if ($item) {
                $item->qty += $requestQty;
                $item->total_price = $item->qty * $item->price;
                if ($request->has('notes')) {
                    $item->notes = $request->notes; 
                }
                $item->save();
            } else {
                $stationId = $product->pivot->station_id ?? $product->station_id;
                $order->items()->create([
                    'product_id' => $product->id,
                    'station_id' => $stationId,
                    'qty' => $requestQty,
                    'price' => $price,
                    'total_price' => $price * $requestQty,
                    'notes' => $request->notes ?? null,
                ]);
            }

            // Potong stok produk di outlet & catat riwayatnya
            $newStock = $availableStock - $requestQty;
            Outlet::findOrFail($order->outlet_id)->products()->updateExistingPivot($product->id, ['stock' => $newStock]);

            \App\Models\StockHistory::create([
                'outlet_id' => $order->outlet_id,
                'product_id' => $product->id,
                'user_id' => auth()->id(),
                'type' => 'sale',
                'quantity' => -$requestQty,
                'final_stock' => $newStock,
                'reference' => 'Tambah Item Kasir: ' . $order->invoice_number,
            ]);

            // Kalkulasi ulang total harga, diskon, dan pajak
            $order->refresh();
            $order->recalculateTotals(); 
            
            DB::commit();

            return response()->json($order->load('items.product'), 200);
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

        if ($order->status !== Order::STATUS_PENDING && $order->status !== 'pending') {
            return response()->json(['message' => 'Order sudah diproses/lunas dan tidak bisa diubah'], 400);
        }

        DB::beginTransaction();
        try {
            $item = $order->items()->lockForUpdate()->findOrFail($itemId);
            $outlet = \App\Models\Outlet::find($order->outlet_id);
            $product = $outlet->products()->where('products.id', $item->product_id)->lockForUpdate()->first();

            // Kembalikan stok ke outlet karena item dibatalkan/dihapus
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
                    'reference' => 'Hapus Item Kasir: ' . $order->invoice_number,
                ]);
            }

            $item->delete();
            
            // Kalkulasi ulang total, diskon, dan pajak setelah item dihapus
            $order->refresh();
            $order->recalculateTotals();

            DB::commit();
            return response()->json($order->load('items.product'), 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
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
            'previous_order_id' => 'nullable',
        ]);

        if (!empty($validated['previous_order_id'])) {
            $oldOrder = \App\Models\Order::where('id', $validated['previous_order_id'])
                ->where('status', 'pending')
                ->where('table_id', $validated['table_id'])
                ->first();

            if ($oldOrder) {
                // Batalkan order lama
                $oldOrder->update(['status' => 'cancelled']);
                
                // Beri tahu aplikasi PosMobile kasir untuk menghilangkan pesanan lama ini dari layar
                broadcast(new \App\Events\OrderUpdated($oldOrder))->toOthers();
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

            // KUNCI UTAMA: Jalankan ulang hitung total bawaan model agar kolom database tersinkronisasi 100%
            $order->recalculateTotals($validated);
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
            'discount_amount' => 'nullable|integer|min:0',
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
                $order->recalculateTotals($validated);
                $order->refresh();

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
            'items.*.notes' => 'nullable|string|max:500',
        ]);

        DB::beginTransaction();
        try {
            $outlet = $order->outlet;

            foreach ($validated['items'] as $itemData) {
                $item = \App\Models\OrderItem::where('id', $itemData['id'])
                    ->where('order_id', $order->id)
                    ->lockForUpdate()
                    ->first();

                if (!$item) continue;

                $oldQty = (int) $item->qty;
                $newQty = (int) $itemData['qty'];
                $diff = $newQty - $oldQty; // positif = nambah, negatif = kurangi

                // Sesuaikan stok produk sesuai selisih qty, konsisten dengan
                // addItem/removeItem/voidItems/cancelItem yang selalu menjaga
                // stok pivot outlet tetap sinkron dengan qty order.
                if ($diff !== 0 && $outlet) {
                    $productPivot = $outlet->products()
                        ->where('products.id', $item->product_id)
                        ->lockForUpdate()
                        ->first();

                    if ($productPivot) {
                        $currentStock = (int) $productPivot->pivot->stock;

                        // diff > 0 (nambah qty) butuh stok dipotong sejumlah diff
                        if ($diff > 0 && $currentStock < $diff) {
                            throw new \Exception("Stok {$productPivot->name} tidak cukup (Sisa: {$currentStock})");
                        }

                        $newStock = $currentStock - $diff;
                        $outlet->products()->updateExistingPivot($item->product_id, ['stock' => $newStock]);

                        \App\Models\StockHistory::create([
                            'outlet_id' => $order->outlet_id,
                            'product_id' => $item->product_id,
                            'user_id' => auth()->id(),
                            'type' => $diff > 0 ? 'sale' : 'restore',
                            'quantity' => -$diff,
                            'final_stock' => $newStock,
                            'reference' => 'Update Item Kasir: ' . $order->invoice_number,
                        ]);
                    }
                }

                $updateData = [
                    'qty' => $newQty,
                    'total_price' => $newQty * (int) $item->price,
                ];

                if (array_key_exists('notes', $itemData)) {
                    $updateData['notes'] = $itemData['notes'];
                }

                $item->update($updateData);
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

        // Catatan: fallback ke env() di sini sengaja dipertahankan sebagai
        // jaring pengaman untuk order LAMA yang mungkin sudah terlanjur dibuat
        // dengan midtrans_server_key_used kosong sebelum proteksi di
        // publicOrder()/checkoutOrder() ditambahkan (lihat catatan di sana).
        // Order BARU dengan server key kosong sekarang langsung ditolak
        // sebelum sempat mengirim transaksi ke Midtrans, sehingga tidak akan
        // pernah memicu webhook nyata dari Midtrans untuk kasus ini lagi.
        $serverKey = $order->midtrans_server_key_used ?: env('MIDTRANS_SERVER_KEY');
        $hashed = hash('sha512', $request->order_id . $request->status_code . $request->gross_amount . $serverKey);

        if ($hashed !== $request->signature_key) {
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        DB::beginTransaction();

        try {
            // Kunci baris order ini untuk mencegah race condition jika Midtrans
            // mengirim webhook yang sama lebih dari sekali secara bersamaan
            // (retry mechanism Midtrans memang umum terjadi).
            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->first();

            // Idempotency guard: order yang statusnya sudah final (paid/cancelled)
            // tidak boleh diproses ulang. Tanpa ini, webhook duplikat akan
            // mengembalikan stok dua kali untuk status cancel/expire, atau
            // membroadcast event PaymentPaid/OrderUpdated berkali-kali.
            if (in_array($lockedOrder->status, [Order::STATUS_PAID, Order::STATUS_CANCELLED], true)) {
                DB::commit();
                return response()->json(['message' => 'Callback already processed']);
            }

            if (in_array($request->transaction_status, ['settlement', 'capture'], true)) {
                // Pastikan perhitungan diskon & pajak tersinkron sebelum status paid & history dibuat.
                // Ini mencegah kasus diskon "hilang" ketika callback datang.
                $order->loadMissing('items');
                $order->recalculateTotals();
                $order->refresh();

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

                $broadcastOrder = $order->fresh()->load(['items.product', 'table', 'payments', 'discount']);
                // broadcast(new \App\Events\OrderCreated($broadcastOrder));
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
