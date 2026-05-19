<?php

namespace App\Http\Controllers;

use App\Events\OrderAccepted;
use App\Models\Order;
use App\Models\OrderAcceptance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderAcceptanceController extends Controller
{
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
            // Untuk requirement: print baru setelah acc/terima,
            // jadi hanya izinkan accept kalau order sudah paid.
            if ($order->status !== Order::STATUS_PAID) {
                return response()->json(['message' => 'Order belum lunas (paid).'], 422);
            }

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
                ]);
            } elseif (is_null($acceptance->accepted_at)) {
                $acceptance->update(['accepted_by' => $user->id, 'accepted_at' => now()]);
            }

            // Reload order dengan relasi dasar untuk payload event
            $broadcastOrder = $order->fresh()->load('items.product', 'table');

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

