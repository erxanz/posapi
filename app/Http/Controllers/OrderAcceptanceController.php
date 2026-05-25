<?php

namespace App\Http\Controllers;

use App\Events\OrderAccepted;
use App\Models\Order;
use App\Models\OrderAcceptance;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderAcceptanceController extends Controller
{
    public function __construct(private readonly OrderService $orderService)
    {
    }
    public function accept(Request $request, Order $order)
    {
        $scope = (string) ($request->input('scope') ?: 'cashier');

        $request->validate([
            'scope' => 'in:cashier,kitchen',
        ]);

        $user = auth()->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        return DB::transaction(function () use ($order, $user, $scope) {
            // Accept dipakai untuk trigger printer di kasir mobile.
            // Midtrans menandai order paid terlebih dahulu, lalu kasir menekan acc untuk cetak.
            if ($order->status === Order::STATUS_CANCELLED) {
                return response()->json(['message' => 'Order sudah dibatalkan.'], 422);
            }

            // Izinkan pesanan diterima jika berstatus PAID, 
            // ATAU jika metodenya bukan cash (online/QRIS) meskipun statusnya masih PENDING di sistem.
            if ($order->status !== Order::STATUS_PAID && $order->payment_method !== 'cash') {
                // Kita izinkan masuk jika dia non-cash dan statusnya pending
                if ($order->status !== 'pending') { 
                    return response()->json(['message' => 'Order tidak valid untuk diterima.'], 422);
                }
            } elseif ($order->status !== Order::STATUS_PAID && $order->payment_method === 'cash') {
                // Kalau cash, tetap wajib paid (kasir sudah terima uang baru boleh dicentang/di-accept)
                return response()->json(['message' => 'Order cash harus paid sebelum diterima.'], 422);
            }

            // Pastikan history_transactions ikut tercatat saat tombol accept/terima
            $this->orderService->syncHistoryTransaction($order->fresh());

            /** @var OrderAcceptance $acceptance */
            $acceptance = OrderAcceptance::query()
                ->where('order_id', $order->id)
                ->where('scope', $scope)
                ->first();

            if (!$acceptance) {
                $acceptance = OrderAcceptance::create([
                    'order_id' => $order->id,
                    'accepted_by' => $user->id,
                    'scope' => $scope,
                    'accepted_at' => now(),
                    'printed_at' => now(),
                ]);
            } elseif (is_null($acceptance->accepted_at)) {
                $acceptance->update([
                    'accepted_by' => $user->id,
                    'accepted_at' => now(),
                    'printed_at' => now(),
                ]);
            }

            // Reload order dengan relasi yang dibutuhkan printer Flutter
            $broadcastOrder = $order->fresh()->load('items.product', 'table', 'payment', 'latestAcceptance');

            // Trigger event agar mobile langsung print saat tombol acc/terima ditekan
            event(new OrderAccepted($broadcastOrder, $scope, $user->id));

            return response()->json([
                'message' => 'Order accepted',
                'order' => $broadcastOrder,
                'acceptance' => [
                    'order_id' => $acceptance->order_id,
                    'scope' => $acceptance->scope,
                    'accepted_at' => $acceptance->accepted_at,
                ]
            ]);
        });
    }
}

