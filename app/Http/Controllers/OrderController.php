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
            Order::with('items.product')
                ->latest()
                ->paginate(10)
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
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
                $product = Product::findOrFail($item['product_id']);

                // ❗ pastikan hanya produk aktif
                if (!$product->is_active) {
                    throw new \Exception("Produk {$product->name} tidak tersedia");
                }

                $subtotal = $product->price * $item['qty'];
                $total += $subtotal;

                $orderItems[] = [
                    'product_id' => $product->id,
                    'qty' => $item['qty'],
                    'price' => $product->price
                ];
            }

            $order = Order::create([
                'user_id' => auth()->id(), // kasir
                'invoice_number' => 'INV-' . time(),
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
     * Display the specified resource.
     */
    public function show(Order $order)
    {
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
