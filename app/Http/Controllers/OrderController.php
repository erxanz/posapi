<?php

namespace App\Http\Controllers;

use App\Models\Discount;
use App\Models\HistoryTransaction;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Table;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    /**
     * List order
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        // Eager load relasi agar data produk, meja, kasir (user), dan outlet terbawa
        $query = Order::with(['items.product', 'table', 'user', 'outlet']);

        // Filter Hak Akses berdasarkan Role
        if ($user->role === 'karyawan') {
            $query->where('outlet_id', $user->outlet_id);
        } elseif ($user->role === 'manager') {
            // Manager melihat semua pesanan dari daftar outlet yang dia miliki
            $outletIds = \App\Models\Outlet::where('owner_id', $user->id)->pluck('id');
            $query->whereIn('outlet_id', $outletIds);
        }
        // Developer tidak dibatasi (bisa melihat semua pesanan)

        // Filter berdasarkan pencarian No Invoice
        if ($request->filled('search')) {
            $query->where('invoice_number', 'like', '%' . $request->search . '%');
        }

        // Filter berdasarkan Status Transaksi
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Limit Pagination
        $limit = $request->input('limit', 10);

        return response()->json(
            $query->latest()->paginate($limit)
        );
    }

    /**
     * DISABLE STORE (gunakan endpoint checkout untuk membuat order)
     */
    public function store(Request $request)
    {
        $total_price = $request->input('total_price', 0);
        $discountAmount = 0;
        if ($request->filled('discount_id')) {
            $promo = Discount::find($request->discount_id);

            // Validasi: Apakah promo aktif, tanggal sesuai, dan subtotal memenuhi min_purchase?
            if ($promo && $promo->is_active && now()->between($promo->start_date, $promo->end_date)) {
                if ($total_price >= $promo->min_purchase) {
                    if ($promo->type === 'percentage') {
                        $discountAmount = ($total_price * $promo->value) / 100;
                    } else {
                        $discountAmount = $promo->value;
                    }
                }
            }
        }

        // Simpan ke tabel orders
        $order = Order::create([
            'subtotal_price' => $total_price,
            'discount_amount' => $discountAmount,
            'total_price' => $total_price - $discountAmount,
            'discount_id' => $request->discount_id,
        ]);

        return response()->json([
            'message' => 'Gunakan endpoint checkout untuk membuat order'
        ], 400);
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

        if ((int) $product->pivot->stock < (int) $request->qty) {
            return response()->json([
                'message' => "Stok {$product->name} tidak cukup"
            ], 400);
        }

        $price = (int) $product->pivot->price;

        DB::beginTransaction();

        try {
            $item = $order->items()->where('product_id', $product->id)->first();

            if ($item) {
                $item->qty += $request->qty;
                $item->total_price = $item->qty * $item->price;
                $item->save();
            } else {
                $order->items()->create([
                    'product_id' => $product->id,
                    'qty' => $request->qty,
                    'price' => $price,
                    'total_price' => $price * $request->qty
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
        $subtotal = (int) $order->items()->sum('total_price');

        $discountType = $overrides['discount_type'] ?? $order->discount_type;
        $discountValue = isset($overrides['discount_value']) ? (int) $overrides['discount_value'] : (int) ($order->discount_value ?? 0);

        $taxType = $overrides['tax_type'] ?? $order->tax_type;
        $taxValue = isset($overrides['tax_value']) ? (int) $overrides['tax_value'] : (int) ($order->tax_value ?? 0);

        $discountAmount = $this->computeAdjustmentAmount($discountType, $discountValue, $subtotal);
        $baseAfterDiscount = max(0, $subtotal - $discountAmount);
        $taxAmount = $this->computeAdjustmentAmount($taxType, $taxValue, $baseAfterDiscount);
        $total = max(0, $baseAfterDiscount + $taxAmount);

        $order->update([
            'subtotal_price' => $subtotal,
            'discount_type' => $discountType,
            'discount_value' => $discountType ? round($discountValue, 2) : null,
            'discount_amount' => $discountAmount,
            'tax_type' => $taxType,
            'tax_value' => $taxType ? round($taxValue, 2) : null,
            'tax_amount' => $taxAmount,
            'total_price' => $total,
        ]);
    }

    private function computeAdjustmentAmount(?string $type, int $value, int $baseAmount): int
    {
        if (!$type || $baseAmount <= 0 || $value <= 0) {
            return 0;
        }

        if ($type === 'percentage') {
            $percent = min(100, max(0, $value));

            return (int) round(($baseAmount * $percent) / 100);
        }

        return min($baseAmount, max(0, $value));
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
     * PUBLIC ORDER (QR CUSTOMER)
     */
    public function publicOrder(Request $request)
    {
        $validated = $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'table_id' => 'required|exists:tables,id',
'customer_name' => 'nullable|string|max:100',
            'discount_type' => 'nullable|in:percentage,nominal',
            'discount_value' => 'nullable|integer|min:0',
            'tax_type' => 'nullable|in:percentage,nominal',
            'tax_value' => 'nullable|integer|min:0',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty' => 'required|integer|min:1'
        ]);

        DB::beginTransaction();

        try {
            $outlet = Outlet::query()->findOrFail($request->outlet_id);
            $adjustments = $this->resolveAdjustmentInput($validated);

            $table = Table::where('id', $request->table_id)
                ->where('outlet_id', $request->outlet_id)
                ->firstOrFail();

            $order = Order::create([
                'outlet_id' => $request->outlet_id,
                'table_id' => $table->id,
                'customer_name' => $request->customer_name,
                'discount_type' => $adjustments['discount_type'],
                'discount_value' => $adjustments['discount_value'],
                'tax_type' => $adjustments['tax_type'],
                'tax_value' => $adjustments['tax_value'],
                'status' => 'pending'
            ]);

            foreach ($request->items as $item) {
                $product = $outlet->products()
                    ->where('products.id', $item['product_id'])
                    ->wherePivot('is_active', true)
                    ->firstOrFail();

                if ((int) $product->pivot->stock < (int) $item['qty']) {
                    throw new \Exception("Stok {$product->name} tidak cukup");
                }

                $price = (int) $product->pivot->price;

                $subtotal = $price * $item['qty'];

                $order->items()->create([
                    'product_id' => $product->id,
                    'qty' => $item['qty'],
                    'price' => $price,
                    'total_price' => $subtotal
                ]);
            }

            $this->recalculateOrderTotals($order);

            $order->update([
                'invoice_number' => 'INV-' . strtoupper(uniqid())
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Pesanan berhasil dibuat',
                'order' => $order->load('items.product')
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => $e->getMessage()
            ], 500);
        }
    }

/**
     * Checkout order (create order + payment + history)
     */
    public function checkoutOrder(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'outlet_id' => 'nullable|exists:outlets,id', // Dibuat nullable agar flutter tidak wajib kirim
            'table_id' => 'required|exists:tables,id',
            'customer_name' => 'nullable|string|max:100',
'notes' => 'nullable|string|max:255',
            'discount_type' => 'nullable|in:percentage,nominal',
            'discount_value' => 'nullable|integer|min:0',
            'tax_type' => 'nullable|in:percentage,nominal',
            'tax_value' => 'nullable|integer|min:0',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty' => 'required|integer|min:1',
            // --- Tangkap data pembayaran dari Flutter ---
            'payment_method' => 'required|string|max:50',
            'amount_paid' => 'required|numeric|min:0',
        ]);

        $outletId = $validated['outlet_id'] ?? $user->outlet_id;
        $outlet = Outlet::findOrFail($outletId);

        if (!$this->canAccessOutlet($outlet->id)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $table = Table::where('id', $validated['table_id'])
            ->where('outlet_id', $outlet->id)
            ->firstOrFail();

        DB::beginTransaction();

        try {
            $adjustments = $this->resolveAdjustmentInput($validated);

            // 1. Buat Order langsung dengan status 'paid'
            $order = Order::create([
                'outlet_id' => $outlet->id,
                'user_id' => $user->id,
                'table_id' => $table->id,
                'customer_name' => $validated['customer_name'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'invoice_number' => $this->generateInvoiceNumber($outlet->id),
                'discount_type' => $adjustments['discount_type'],
                'discount_value' => $adjustments['discount_value'],
                'tax_type' => $adjustments['tax_type'],
                'tax_value' => $adjustments['tax_value'],
                'status' => 'paid', // Karena langsung dibayar
                'total_price' => 0,
            ]);

            foreach ($validated['items'] as $item) {
                $product = $outlet->products()
                    ->where('products.id', $item['product_id'])
                    ->wherePivot('is_active', true)
                    ->lockForUpdate()
                    ->firstOrFail();

                $stock = (int) $product->pivot->stock;
                $qty = (int) $item['qty'];

                if ($stock < $qty) {
                    throw new \Exception("Stok {$product->name} tidak cukup (Sisa: {$stock})");
                }

                $price = (int) $product->pivot->price;
                $subtotal = $price * $qty;
                $stationId = $product->pivot->station_id ?? $product->station_id;

                $order->items()->create([
                    'product_id' => $product->id,
                    'station_id' => $stationId,
                    'qty' => $qty,
                    'price' => $price,
                    'total_price' => $subtotal,
                ]);

                // Potong Stok
                $newStock = $stock - $qty;
                $outlet->products()->updateExistingPivot($product->id, ['stock' => $newStock]);

                \App\Models\StockHistory::create([
                    'outlet_id' => $outlet->id,
                    'product_id' => $product->id,
                    'user_id' => $user->id,
                    'type' => 'sale',
                    'quantity' => -$qty,
                    'final_stock' => $newStock,
                    'reference' => 'Sales Order: ' . $order->invoice_number
                ]);
            }

            $this->recalculateOrderTotals($order);

            // 2. Buat Data Pembayaran (Payment)
            $amountPaid = (int) $validated['amount_paid'];
            $changeAmount = max(0, $amountPaid - $order->total_price);

            Payment::create([
                'order_id' => $order->id,
                'amount_paid' => $amountPaid,
                'change_amount' => $changeAmount,
                'method' => strtolower($validated['payment_method']),
                'paid_at' => now(),
                'paid_by' => $user->id,
            ]);

            // 3. Masukkan ke tabel History Transactions!
            $this->storeHistoryTransaction($order);

            $table->update([
                'status' => 'available', // Kosongkan meja kembali
            ]);

            DB::commit();

            return response()->json([
                'success' => true, // Wajib untuk trigger UI Flutter
                'message' => 'Checkout dan pembayaran berhasil',
                'order' => $order->load('items.product', 'table', 'payments'),
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Payment endpoint (supports split payment)
     */
    public function pay(Request $request, $orderId)
    {
        $validated = $request->validate([
            'payments' => 'required|array|min:1',
            'payments.*.amount_paid' => 'required|integer|min:1',
            'payments.*.method' => 'required|string|max:50',
            'payments.*.reference_no' => 'nullable|string|max:100',
        ]);

        $order = Order::where('id', $orderId)
            ->where('outlet_id', auth()->user()->outlet_id)
            ->firstOrFail();

        if ($order->status === 'paid') {
            return response()->json(['message' => 'Order sudah dibayar'], 400);
        }

        if ($order->status === 'cancelled') {
            return response()->json(['message' => 'Order sudah dibatalkan'], 400);
        }

        if ($order->items()->count() === 0) {
            return response()->json(['message' => 'Order tidak memiliki item'], 400);
        }

        DB::beginTransaction();

        try {
            $alreadyPaid = (int) $order->payments()->sum(DB::raw('amount_paid - change_amount'));
            $remaining = max(0, (int) $order->total_price - $alreadyPaid);
            $createdPayments = [];

            foreach ($validated['payments'] as $row) {
                if ($remaining <= 0) {
                    break;
                }

                $amount = (int) $row['amount_paid'];
                $appliedAmount = min($amount, $remaining);
                $changeAmount = max(0, $amount - $appliedAmount);

                $payment = Payment::create([
                    'order_id' => $order->id,
                    'amount_paid' => $amount,
                    'change_amount' => $changeAmount,
                    'method' => $row['method'],
                    'reference_no' => $row['reference_no'] ?? null,
                    'paid_at' => now(),
                    'paid_by' => auth()->id(),
                ]);

                $createdPayments[] = $payment;
                $remaining -= $appliedAmount;
            }

            $effectivePaid = (int) $order->payments()->sum(DB::raw('amount_paid - change_amount'));
            $isPaid = $effectivePaid >= (int) $order->total_price;

            if ($isPaid) {
                $order->update(['status' => 'paid']);

                $this->storeHistoryTransaction($order);

                if ($order->table_id) {
                    $order->table()->update(['status' => 'available']);
                }
            }

            DB::commit();

            return response()->json([
                'message' => $isPaid ? 'Pembayaran berhasil, order lunas' : 'Pembayaran tercatat, order belum lunas',
                'order' => $order->fresh()->load('items.product', 'payments'),
                'payment_summary' => [
                    'order_total' => (int) $order->total_price,
                    'effective_paid' => $effectivePaid,
                    'remaining' => max(0, (int) $order->total_price - $effectivePaid),
                    'is_paid' => $isPaid,
                ],
                'payments_created' => $createdPayments,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Checkout (bayar)
     */
    public function checkout(Request $request, $orderId)
    {
        $validated = $request->validate([
            'paid_amount' => 'required|integer|min:1',
            'method' => 'nullable|string|max:50',
            'reference_no' => 'nullable|string|max:100',
        ]);

        $request->merge([
            'payments' => [[
                'amount_paid' => $validated['paid_amount'],
                'method' => $validated['method'] ?? 'cash',
                'reference_no' => $validated['reference_no'] ?? null,
            ]],
        ]);

        return $this->pay($request, $orderId);
    }

    public function updateAdjustments(Request $request, $orderId)
    {
        $validated = $request->validate([
'discount_type' => 'nullable|in:percentage,nominal',
            'discount_value' => 'nullable|integer|min:0',
            'tax_type' => 'nullable|in:percentage,nominal',
            'tax_value' => 'nullable|integer|min:0',
        ]);

        $order = Order::where('id', $orderId)
            ->where('outlet_id', auth()->user()->outlet_id)
            ->firstOrFail();

        if ($order->status !== 'pending') {
            return response()->json(['message' => 'Adjustment hanya bisa untuk order pending'], 400);
        }

        $adjustments = $this->resolveAdjustmentInput($validated);

        $this->recalculateOrderTotals($order, [
            'discount_type' => $adjustments['discount_type'],
            'discount_value' => $adjustments['discount_type'] ? (int) $adjustments['discount_value'] : 0,
            'tax_type' => $adjustments['tax_type'],
            'tax_value' => $adjustments['tax_type'] ? (int) $adjustments['tax_value'] : 0,
        ]);

        return response()->json([
            'message' => 'Diskon dan pajak berhasil diperbarui',
            'order' => $order->fresh()->load('items.product', 'table'),
        ]);
    }

    private function canAccessOutlet(int $outletId): bool
    {
        $user = auth()->user();

        if ($user->role === 'developer') {
            return true;
        }

        return (int) $user->outlet_id === $outletId;
    }

    private function resolveAdjustmentInput(array $validated): array
    {
        $discountType = $validated['discount_type'] ?? null;
        $discountValue = isset($validated['discount_value']) ? (int) $validated['discount_value'] : null;
        $taxType = $validated['tax_type'] ?? null;
        $taxValue = isset($validated['tax_value']) ? (int) $validated['tax_value'] : null;

        // Jika ada request diskon yang dikirim (tidak null)
        if ($discountType !== null || $discountValue !== null) {
            $user = auth()->user();

            // Tolak jika tidak ada user (publik) ATAU user bukan manager/developer
            if (!$user || !in_array($user->role, ['manager', 'developer'])) {
                throw ValidationException::withMessages([
                    'discount' => ['Akses ditolak: Hanya Manager yang dapat memberikan diskon.'],
                ]);
            }
        }

        if ($discountType && $discountValue === null) {
            throw ValidationException::withMessages([
                'discount_value' => ['discount_value wajib diisi jika discount_type dipilih'],
            ]);
        }

        if ($taxType && $taxValue === null) {
            throw ValidationException::withMessages([
                'tax_value' => ['tax_value wajib diisi jika tax_type dipilih'],
            ]);
        }

        if ($discountType === 'percentage' && $discountValue > 100) {
            throw ValidationException::withMessages([
                'discount_value' => ['discount_value persen maksimal 100'],
            ]);
        }

        if ($taxType === 'percentage' && $taxValue > 100) {
            throw ValidationException::withMessages([
                'tax_value' => ['tax_value persen maksimal 100'],
            ]);
        }

        return [
            'discount_type' => $discountType,
            'discount_value' => $discountType ? (int) $discountValue : null,
            'tax_type' => $taxType,
            'tax_value' => $taxType ? (int) $taxValue : null,
        ];
    }

    private function generateInvoiceNumber(int $outletId): string
    {
        return 'INV-' . str_pad((string) $outletId, 2, '0', STR_PAD_LEFT) . '-' . now()->format('YmdHis') . '-' . strtoupper(substr((string) uniqid(), -4));
    }

    private function storeHistoryTransaction(Order $order): void
    {
        $order->load(['payments', 'items']);

        $lastPayment = $order->payments->sortByDesc('id')->first();
        $methods = $order->payments
            ->pluck('method')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $paymentMethod = null;

        if (count($methods) === 1) {
            $paymentMethod = $methods[0];
        } elseif (count($methods) > 1) {
            $paymentMethod = 'split';
        }

        $paidAmount = (int) $order->payments->sum(fn ($payment) => (int) $payment->amount_paid - (int) $payment->change_amount);

        $changeAmount = (int) $order->payments->sum('change_amount');

        HistoryTransaction::updateOrCreate(
            ['order_id' => $order->id],
            [
                'outlet_id' => $order->outlet_id,
'payment_id' => $lastPayment?->id ?? null,
                'invoice_number' => $order->invoice_number,
                'customer_name' => $order->customer_name,
                'subtotal_price' => (int) $order->subtotal_price,
                'discount_amount' => (int) $order->discount_amount,
                'tax_amount' => (int) $order->tax_amount,
                'total_price' => (int) $order->total_price,
                'paid_amount' => $paidAmount,
                'change_amount' => $changeAmount,
                'payment_method' => $paymentMethod,
                'paid_at' => $lastPayment?->paid_at ?? now(),
'cashier_id' => $lastPayment?->paid_by ?? null,
                'status' => 'paid',
                'metadata' => [
                    'payments_count' => $order->payments->count(),
                    'methods' => $methods,
                    'items_count' => $order->items->count(),
                ],
            ]
        );
    }

    /**
     * VOID / CANCEL ITEM (Parsial)
     */
    public function voidItems(Request $request, $orderId)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:255',
            'items' => 'required|array',
            'items.*.id' => 'required|exists:order_items,id',
            'items.*.cancelled_qty' => 'required|integer|min:0'
        ]);

        $order = Order::where('id', $orderId)
            ->where('outlet_id', auth()->user()->outlet_id)
            ->with('items.product')
            ->firstOrFail();

        // Tidak bisa void jika order sudah dibatalkan full
        if ($order->status === 'cancelled') {
            return response()->json(['message' => 'Order sudah dibatalkan sepenuhnya'], 400);
        }

        DB::beginTransaction();

        try {
            $actionDetails = [];
            $outlet = Outlet::find($order->outlet_id);

            foreach ($validated['items'] as $inputItem) {
                $item = $order->items->where('id', $inputItem['id'])->first();

                if (!$item) continue;

                $oldCancelledQty = (int) $item->cancelled_qty;
                $newCancelledQty = (int) $inputItem['cancelled_qty'];

                // Jika ada perubahan pada qty yang dibatalkan
                if ($oldCancelledQty !== $newCancelledQty) {
                    $diff = $newCancelledQty - $oldCancelledQty; // Jika positif berarti nambah void

                    if ($diff > 0) {
                        $actionDetails[] = "Void {$diff}x " . $item->product->name;
                    } else {
                        $actionDetails[] = "Restore " . abs($diff) . "x " . $item->product->name;
                    }

                    // 1. Update qty yang dibatalkan & sesuaikan total_price item
                    $item->cancelled_qty = $newCancelledQty;
                    $item->total_price = ($item->qty - $newCancelledQty) * $item->price;
                    $item->save();

                    // 2. Kembalikan / Kurangi Stok Outlet
                    $outletProduct = $outlet->products()->where('products.id', $item->product_id)->first();
                    if ($outletProduct) {
                        $currentStock = $outletProduct->pivot->stock;
                        $newStock = $currentStock + $diff;

                        $outlet->products()->updateExistingPivot($item->product_id, [
                            'stock' => $newStock
                        ]);

                        // Tambahkan log ke Stock History
                        \App\Models\StockHistory::create([
                            'outlet_id' => $order->outlet_id,
                            'product_id' => $item->product_id,
                            'user_id' => auth()->id(),
                            'type' => 'void',
                            'quantity' => $diff, // positif = balik ke stok, negatif = pengurangan
                            'final_stock' => $newStock,
                            'reference' => 'Void Order: ' . $order->invoice_number
                        ]);
                    }
                }
            }

            // Jika benar-benar ada yang berubah
            if (count($actionDetails) > 0) {
                // Hitung ulang Grand Total Order
                $this->recalculateOrderTotals($order);

                // Catat ke Log
                $logs = $order->logs ?? [];
                array_unshift($logs, [
                    'date' => now()->format('d M Y H:i'),
                    'action' => implode(', ', $actionDetails),
                    'reason' => $validated['reason'],
                    'by' => auth()->user()->name
                ]);

                $order->logs = $logs;
                $order->save();
            }

            DB::commit();

            return response()->json([
                'message' => 'Item berhasil di-void dan total diperbarui',
                'order' => $order->fresh()->load('items.product', 'table', 'user')
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete (tidak digunakan)
     */
    public function destroy(string $id)
    {
        return response()->json([
            'message' => 'Fitur delete order tidak tersedia'
        ], 400);
    }
}
