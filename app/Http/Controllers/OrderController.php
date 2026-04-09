<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
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
     * DISABLE STORE (gunakan endpoint checkout untuk membuat order)
     */
    public function store(Request $request)
    {
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
            $outlet = Outlet::query()->findOrFail($request->outlet_id);

            $table = Table::where('id', $request->table_id)
                ->where('outlet_id', $request->outlet_id)
                ->firstOrFail();

            $order = Order::create([
                'outlet_id' => $request->outlet_id,
                'table_id' => $table->id,
                'customer_name' => $request->customer_name,
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
                $total += $subtotal;

                $order->items()->create([
                    'product_id' => $product->id,
                    'qty' => $item['qty'],
                    'price' => $price,
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
     * Checkout order (create order + order_items)
     */
    public function checkoutOrder(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'table_id' => 'required|exists:tables,id',
            'customer_name' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.qty' => 'required|integer|min:1',
        ]);

        $outlet = Outlet::findOrFail($validated['outlet_id']);

        if (!$this->canAccessOutlet($outlet->id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $table = Table::where('id', $validated['table_id'])
            ->where('outlet_id', $outlet->id)
            ->firstOrFail();

        DB::beginTransaction();

        try {
            $order = Order::create([
                'outlet_id' => $outlet->id,
                'user_id' => $user->id,
                'table_id' => $table->id,
                'customer_name' => $validated['customer_name'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'invoice_number' => $this->generateInvoiceNumber($outlet->id),
                'status' => 'pending',
                'total_price' => 0,
            ]);

            $total = 0;

            foreach ($validated['items'] as $item) {
                $product = $outlet->products()
                    ->where('products.id', $item['product_id'])
                    ->wherePivot('is_active', true)
                    ->firstOrFail();

                $stock = (int) $product->pivot->stock;
                $qty = (int) $item['qty'];

                if ($stock < $qty) {
                    throw new \Exception("Stok {$product->name} tidak cukup");
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

                $total += $subtotal;
            }

            $order->update([
                'total_price' => $total,
            ]);

            $table->update([
                'status' => 'occupied',
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Checkout order berhasil dibuat',
                'order' => $order->load('items.product', 'table'),
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
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

    private function canAccessOutlet(int $outletId): bool
    {
        $user = auth()->user();

        if ($user->role === 'developer') {
            return true;
        }

        return (int) $user->outlet_id === $outletId;
    }

    private function generateInvoiceNumber(int $outletId): string
    {
        return 'INV-' . str_pad((string) $outletId, 2, '0', STR_PAD_LEFT) . '-' . now()->format('YmdHis') . '-' . strtoupper(substr((string) uniqid(), -4));
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
