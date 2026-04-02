<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Product;
use App\Models\Table;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    /**
     * List order
     */
    public function index()
    {
        return response()->json(
            Order::where('outlet_id', auth()->user()->outlet_id)
                ->with('items.product', 'table')
                ->latest()
                ->paginate(10)
        );
    }

    /**
     * DISABLE STORE (order harus dari meja)
     */
    public function store(Request $request)
    {
        return response()->json([
            'message' => 'Gunakan endpoint openTable untuk membuat order'
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

        // hanya order OPEN yang bisa diubah
        if ($order->status !== 'open') {
            return response()->json(['message' => 'Order sudah ditutup'], 400);
        }

        // ambil product sesuai outlet (SECURITY FIX)
        $product = Product::where('id', $request->product_id)
            ->where('outlet_id', auth()->user()->outlet_id)
            ->firstOrFail();

        if (!$product->is_active) {
            return response()->json([
                'message' => "Produk {$product->name} tidak tersedia"
            ], 400);
        }

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
                    'price' => $product->price,
                    'total_price' => $product->price * $request->qty
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
        $total = $order->items()->sum('total_price');

        $order->update([
            'total_price' => $total
        ]);
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
     * Hapus item dari order
     */
    public function removeItem($orderId, $itemId)
    {
        $order = Order::where('id', $orderId)
            ->where('outlet_id', auth()->user()->outlet_id)
            ->firstOrFail();

        if ($order->status !== 'open') {
            return response()->json(['message' => 'Order sudah ditutup'], 400);
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
        $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'table_id' => 'required|exists:tables,id',
            'customer_name' => 'nullable|string|max:100',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty' => 'required|integer|min:1'
        ]);

        DB::beginTransaction();

        try {
            $total = 0;

            $table = Table::where('id', $request->table_id)
                ->where('outlet_id', $request->outlet_id)
                ->firstOrFail();

            $order = Order::create([
                'outlet_id' => $request->outlet_id,
                'table_id' => $table->id,
                'customer_name' => $request->customer_name,
                'status' => 'open'
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
     * Checkout (bayar)
     */
    public function checkout(Request $request, $orderId)
    {
        $request->validate([
            'paid_amount' => 'required|numeric|min:0'
        ]);

        $order = Order::where('id', $orderId)
            ->where('outlet_id', auth()->user()->outlet_id)
            ->firstOrFail();

        if ($order->status !== 'open') {
            return response()->json(['message' => 'Order sudah dibayar'], 400);
        }

        if ($order->items()->count() === 0) {
            return response()->json(['message' => 'Keranjang kosong'], 400);
        }

        $paid = $request->paid_amount;

        if ($paid < $order->total_price) {
            return response()->json(['message' => 'Uang kurang'], 400);
        }

        $change = $paid - $order->total_price;

        DB::beginTransaction();

        try {
            $order->update([
                'status' => 'paid',
                'invoice_number' => $order->invoice_number ?? 'INV-' . strtoupper(uniqid())
            ]);

            // UPDATE STATUS MEJA
            if ($order->table_id) {
                $order->table->update([
                    'status' => 'available'
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Transaksi berhasil',
                'order' => $order->load('items.product'),
                'paid' => $paid,
                'change' => $change
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => $e->getMessage()
            ], 500);
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
