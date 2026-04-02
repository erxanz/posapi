<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(
            Order::where('outlet_id', auth()->user()->outlet_id)
                ->with('items.product')
                ->latest()
                ->paginate(10)
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        if (!$user->outlet_id) {
            return response()->json(['message' => 'User belum punya outlet'], 400);
        }

        if (!$request->has('items')) {
            // Create draft order
            $order = Order::create([
                'outlet_id' => $user->outlet_id,
                'user_id' => $user->id,
                'status' => 'draft'
            ]);

            return response()->json($order);
        }

        // Checkout with items
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty' => 'required|integer|min:1'
        ]);

        DB::beginTransaction();

        try {
            $total = 0;
            $orderItems = [];

            foreach ($request->items as $item) {
                $product = Product::where('id', $item['product_id'])
                    ->where('outlet_id', $user->outlet_id)
                    ->firstOrFail();

                // pastikan hanya produk aktif
                if (!$product->is_active) {
                    throw new \Exception("Produk {$product->name} tidak tersedia");
                }

                $subtotal = $product->price * $item['qty'];
                $total += $subtotal;

                $orderItems[] = [
                    'product_id' => $product->id,
                    'qty' => $item['qty'],
                    'price' => $product->price,
                    'total_price' => $subtotal
                ];
            }

            $order = Order::create([
                'outlet_id' => $user->outlet_id,
                'user_id' => $user->id,
                'invoice_number' => 'INV-' . strtoupper(uniqid()),
                'total_price' => $total,
                'status' => 'paid'
            ]);

            foreach ($orderItems as $item) {
                $order->items()->create($item);
            }

            DB::commit();

            return response()->json([
                'message' => 'Checkout berhasil',
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
     * Tambah Item (CART)
     */
    public function addItem(Request $request, $orderId)
    {
        // Validasi input
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'qty' => 'required|integer|min:1'
        ]);

        // Cari order, pastikan status masih draft dan milik outlet yang sama
        $order = Order::where('id', $orderId)
            ->where('outlet_id', auth()->user()->outlet_id)
            ->firstOrFail();

        if ($order->status !== 'draft') {
            return response()->json(['message' => 'Hanya order draft yang bisa ditambah produknya'], 400);
        }

        // Pastikan order milik outlet yang sama (security check)
        if ($order->outlet_id !== auth()->user()->outlet_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Cari product
        $product = Product::findOrFail($request->product_id);

        // Pastikan hanya produk aktif
        if (!$product->is_active) {
            return response()->json([
                'message' => "Produk {$product->name} tidak tersedia"
            ], 400);
        }

        // Cek apakah product sudah ada di cart
        $item = $order->items()->where('product_id', $product->id)->first();

        if ($item) {
            // Update qty jika sudah ada
            $item->qty += $request->qty;
            $item->total_price = $item->qty * $item->price;
            $item->save();
        } else {
            // Create item baru jika belum ada
            $order->items()->create([
                'product_id' => $product->id,
                'qty' => $request->qty,
                'price' => $product->price,
                'total_price' => $product->price * $request->qty
            ]);
        }

        // Update total harga order
        $this->updateTotal($order);

        return response()->json($order->load('items.product'), 201);
    }

    /**
     * Helper method: Update total price order
     * Menggunakan sum subtotal dari items untuk kalkulasi total yang lebih akurat
     */
    private function updateTotal($order)
    {
        $total = $order->items()->sum('total_price');

        $order->update([
            'total_price' => $total
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Order $order)
    {
        if ($order->outlet_id !== auth()->user()->outlet_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(
            $order->load('items.product')
        );
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        // belum ada fitur update order, karena biasanya order sudah final saat checkout
        return response()->json([
            'message' => 'Fitur update order belum tersedia'
        ], 400);
    }

    /**
     * Hapus Item
     */
    public function removeItem($orderId, $itemId)
    {
        $order = Order::where('id', $orderId)
            ->where('outlet_id', auth()->user()->outlet_id)
            ->firstOrFail();

        if ($order->status !== 'draft') {
            return response()->json(['message' => 'Hanya order draft yang bisa diubah'], 400);
        }

        // Pastikan order milik outlet yang sama (security check)
        if ($order->outlet_id !== auth()->user()->outlet_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $item = $order->items()->findOrFail($itemId);
        $item->delete();

        $this->updateTotal($order);

        return response()->json($order->load('items.product'));
    }

    /**
     * PUBLIC ORDER (QR CUSTOMER - TANPA LOGIN)
     */
    public function publicOrder(Request $request)
    {
        $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'table_id' => 'required|exists:tables,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty' => 'required|integer|min:1'
        ]);

        DB::beginTransaction();

        try {
            $total = 0;

            // validasi table milik outlet
            $table = \App\Models\Table::where('id', $request->table_id)
                ->where('outlet_id', $request->outlet_id)
                ->firstOrFail();

            $order = Order::create([
                'outlet_id' => $request->outlet_id,
                'table_id' => $table->id,
                'status' => 'pending'
            ]);

            foreach ($request->items as $item) {
                $product = Product::where('id', $item['product_id'])
                    ->where('outlet_id', $request->outlet_id)
                    ->firstOrFail();

                if (!$product->is_active) {
                    throw new \Exception("Produk {$product->name} tidak tersedia");
                }

                $subtotal = $product->price * $item['qty'];
                $total += $subtotal;

                $order->items()->create([
                    'product_id' => $product->id,
                    'qty' => $item['qty'],
                    'price' => $product->price,
                    'total_price' => $subtotal
                ]);
            }

            $order->update([
                'total_price' => $total,
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
     * Checkout Order
     */
    public function checkout(Request $request, $orderId)
    {
        $request->validate([
            'paid_amount' => 'required|numeric|min:0'
        ]);

        $order = Order::where('id', $orderId)
            ->where('outlet_id', auth()->user()->outlet_id)
            ->firstOrFail();

        if ($order->outlet_id !== auth()->user()->outlet_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($order->status !== 'draft') {
            return response()->json(['message' => 'Sudah dibayar'], 400);
        }

        if ($order->items()->count() === 0) {
            return response()->json(['message' => 'Keranjang masih kosong'], 400);
        }

        $paid = $request->paid_amount;

        if ($paid < $order->total_price) {
            return response()->json(['message' => 'Uang kurang'], 400);
        }

        $change = $paid - $order->total_price;

        $order->update([
            'status' => 'paid',
            'invoice_number' => $order->invoice_number ?? 'INV-' . strtoupper(uniqid())
        ]);

        return response()->json([
            'message' => 'Transaksi berhasil',
            'order' => $order->load('items.product'),
            'paid' => $paid,
            'change' => $change
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        // belum ada fitur delete order, karena biasanya order sudah final saat checkout
        return response()->json([
            'message' => 'Fitur delete order belum tersedia'
        ], 400);
    }
}
