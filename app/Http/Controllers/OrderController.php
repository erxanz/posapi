<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    /**
     * GET /orders
     * List orders dengan filter
     *
     * SECURITY:
     * - Manager: hanya orders di outlet miliknya
     * - Karyawan: hanya orders di outlet miliknya
     * - Developer: semua orders
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        $query = Order::query();

        // SECURITY: Filter by outlet
        if ($user->isManager()) {
            $outletIds = $user->ownedOutlets()->pluck('id')->toArray();
            $query->whereIn('outlet_id', $outletIds);
        } elseif ($user->isKaryawan()) {
            $query->where('outlet_id', $user->outlet_id);
        }

        // Optional filters
        if ($request->filled('outlet_id')) {
            if (!$user->canAccessOutlet($request->outlet_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Forbidden',
                ], 403);
            }
            $query->where('outlet_id', $request->outlet_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('table_id')) {
            $query->where('table_id', $request->table_id);
        }

        $orders = $query->with(['items.product', 'table', 'outlet', 'user'])
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $orders,
        ]);
    }

    /**
     * POST /orders
     * Create order baru (draft/pending)
     *
     * SECURITY:
     * - Hanya logged-in user yang bisa create
     * - Validasi table belongs to outlet
     * - Auto-assign user_id dari auth
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'table_id' => 'required|exists:tables,id',
            'customer_name' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        // SECURITY: Check user dapat akses outlet
        if (!$user->canAccessOutlet($validated['outlet_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: Tidak dapat akses outlet ini',
            ], 403);
        }

        // SECURITY: Validasi table belongs to outlet
        $table = Table::where('id', $validated['table_id'])
            ->where('outlet_id', $validated['outlet_id'])
            ->firstOrFail();

        // SECURITY: Generate unique invoice number
        $invoiceNumber = 'INV-' . date('YmdHis') . '-' . Str::random(6);

        $order = Order::create([
            'outlet_id' => $validated['outlet_id'],
            'user_id' => $user->id,
            'table_id' => $table->id,
            'customer_name' => $validated['customer_name'],
            'notes' => $validated['notes'],
            'invoice_number' => $invoiceNumber,
            'total_price' => 0,
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Order berhasil dibuat',
            'data' => $order->load('items', 'table', 'outlet'),
        ], 201);
    }

    /**
     * GET /orders/{order}
     * Show detail order dengan items
     */
    public function show(Request $request, Order $order)
    {
        $user = auth()->user();

        // SECURITY: Check akses
        if (!$order->canBeAccessedBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $order->load('items.product', 'items.station', 'table', 'outlet', 'user'),
        ]);
    }

    /**
     * POST /orders/{order}/items
     * Add item ke order
     *
     * SECURITY:
     * - Order harus status pending
     * - Product harus dari outlet yang sama
     * - Validasi stock
     */
    public function addItem(Request $request, Order $order)
    {
        $user = auth()->user();

        // SECURITY: Check akses order
        if (!$order->canBeAccessedBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        // SECURITY: Order harus pending
        if (!$order->canBeModified()) {
            return response()->json([
                'success' => false,
                'message' => 'Order sudah tidak bisa dimodifikasi',
            ], 422);
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'station_id' => 'nullable|exists:stations,id',
            'qty' => 'required|integer|min:1',
        ]);

        // SECURITY: Product harus dari outlet yang sama
        $product = Product::where('id', $validated['product_id'])
            ->where('outlet_id', $order->outlet_id)
            ->firstOrFail();

        if (!$product->is_active) {
            return response()->json([
                'success' => false,
                'message' => "Produk {$product->name} tidak tersedia",
            ], 422);
        }

        // SECURITY: Validasi stock
        if ($product->stock < $validated['qty']) {
            return response()->json([
                'success' => false,
                'message' => "Stok {$product->name} tidak cukup (tersedia: {$product->stock})",
            ], 422);
        }

        // SECURITY: Validasi station belongs to outlet (jika ada)
        if ($validated['station_id']) {
            $station = \App\Models\Station::where('id', $validated['station_id'])
                ->where('outlet_id', $order->outlet_id)
                ->firstOrFail();
        }

        DB::beginTransaction();

        try {
            // Check apakah product sudah ada di order
            $existingItem = $order->items()->where('product_id', $product->id)->first();

            if ($existingItem) {
                // Update qty jika sudah ada
                $existingItem->qty += $validated['qty'];
                $existingItem->total_price = $existingItem->qty * $existingItem->price;
                $existingItem->save();
                $item = $existingItem;
            } else {
                // Create baru
                $item = $order->items()->create([
                    'product_id' => $product->id,
                    'station_id' => $validated['station_id'] ?? null,
                    'qty' => $validated['qty'],
                    'price' => $product->price,
                    'total_price' => $product->price * $validated['qty'],
                ]);
            }

            // Update order total
            $newTotal = $order->items()->sum('total_price');
            $order->update(['total_price' => $newTotal]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Item berhasil ditambahkan',
                'data' => $order->load('items.product', 'items.station'),
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /orders/{order}/items/{item}
     * Remove item dari order
     *
     * SECURITY:
     * - Order harus pending
     * - Item harus belong to order
     */
    public function removeItem(Request $request, Order $order, OrderItem $item)
    {
        $user = auth()->user();

        // SECURITY: Check akses order
        if (!$order->canBeAccessedBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        // SECURITY: Item harus belong to order
        if ($item->order_id != $order->id) {
            return response()->json([
                'success' => false,
                'message' => 'Item tidak sesuai dengan order',
            ], 422);
        }

        // SECURITY: Order harus pending
        if (!$order->canBeModified()) {
            return response()->json([
                'success' => false,
                'message' => 'Order sudah tidak bisa dimodifikasi',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $item->delete();

            // Update order total
            $newTotal = $order->items()->sum('total_price');
            $order->update(['total_price' => $newTotal]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Item berhasil dihapus',
                'data' => $order->load('items.product'),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /orders/{order}/checkout
     * Checkout order (ubah status ke paid)
     *
     * SECURITY:
     * - Order harus pending
     * - Validasi order tidak kosong
     * - Update table status ke available
     */
    public function checkout(Request $request, Order $order)
    {
        $user = auth()->user();

        // SECURITY: Check akses order
        if (!$order->canBeAccessedBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        // SECURITY: Order harus pending
        if ($order->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Order sudah dibayar atau dibatalkan',
            ], 422);
        }

        // SECURITY: Order tidak boleh kosong
        if ($order->items()->count() === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Keranjang kosong, tidak bisa checkout',
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Update order status
            $order->update(['status' => 'paid']);

            // Update table status back to available
            if ($order->table) {
                $order->table->update(['status' => 'available']);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Pembayaran berhasil',
                'data' => $order->load('items.product', 'table'),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /public/order
     * Public API - Create order dari QR code
     *
     * SECURITY:
     * - Validasi table belongs to outlet
     * - Validasi semua products dari outlet yang sama
     * - Auto generate invoice
     * - Customer tidak perlu login
     */
    public function publicOrder(Request $request)
    {
        $validated = $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'table_id' => 'required|exists:tables,id',
            'customer_name' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty' => 'required|integer|min:1',
        ]);

        // SECURITY: Validasi table belongs to outlet
        $table = Table::where('id', $validated['table_id'])
            ->where('outlet_id', $validated['outlet_id'])
            ->where('is_active', true)
            ->firstOrFail();

        DB::beginTransaction();

        try {
            // Generate invoice
            $invoiceNumber = 'INV-' . date('YmdHis') . '-' . Str::random(6);

            // Create order
            $order = Order::create([
                'outlet_id' => $validated['outlet_id'],
                'user_id' => null, // Public order, no user
                'table_id' => $table->id,
                'customer_name' => $validated['customer_name'],
                'invoice_number' => $invoiceNumber,
                'total_price' => 0,
                'status' => 'pending',
            ]);

            $totalPrice = 0;

            // Add items
            foreach ($validated['items'] as $itemData) {
                // SECURITY: Product harus dari outlet yang sama
                $product = Product::where('id', $itemData['product_id'])
                    ->where('outlet_id', $validated['outlet_id'])
                    ->firstOrFail();

                if (!$product->is_active) {
                    throw new \Exception("Produk {$product->name} tidak tersedia");
                }

                if ($product->stock < $itemData['qty']) {
                    throw new \Exception("Stok {$product->name} tidak cukup");
                }

                $itemTotal = $product->price * $itemData['qty'];
                $totalPrice += $itemTotal;

                $order->items()->create([
                    'product_id' => $product->id,
                    'station_id' => $product->station_id,
                    'qty' => $itemData['qty'],
                    'price' => $product->price,
                    'total_price' => $itemTotal,
                ]);
            }

            // Update order total
            $order->update(['total_price' => $totalPrice]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Pesanan berhasil dibuat',
                'data' => [
                    'invoice_number' => $order->invoice_number,
                    'table_name' => $table->name,
                    'customer_name' => $order->customer_name,
                    'total_price' => $order->total_price,
                    'items' => $order->items()->with('product')->get(),
                ]
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * PATCH /order-items/{item}/status
     * Update status item (pending → cooking → done)
     * Untuk kitchen display
     */
    public function updateItemStatus(Request $request, OrderItem $item)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'status' => 'required|in:pending,cooking,done',
        ]);

        // SECURITY: Check akses ke order
        $order = $item->order;
        if (!$order->canBeAccessedBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        $item->update(['status' => $validated['status']]);

        return response()->json([
            'success' => true,
            'message' => 'Status item berhasil diupdate',
            'data' => $item,
        ]);
    }

    /**
     * POST /orders/{order}/cancel
     * Cancel order (ubah status ke cancelled)
     */
    public function cancel(Request $request, Order $order)
    {
        $user = auth()->user();

        // SECURITY: Check akses
        if (!$order->canBeAccessedBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        // SECURITY: Hanya order pending yang bisa dibatalkan
        if ($order->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya order pending yang bisa dibatalkan',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $order->update(['status' => 'cancelled']);

            // Release table
            if ($order->table) {
                $order->table->update(['status' => 'available']);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order berhasil dibatalkan',
                'data' => $order,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ], 500);
        }
    }
}
