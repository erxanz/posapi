<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\StockHistory;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AutoCancelOrders extends Command
{
    protected $signature = 'orders:auto-cancel';
    protected $description = 'Membatalkan pesanan pending yang usianya lewat 30 menit dan mengembalikan stok';

    public function handle()
    {
        $expiredOrders = Order::with('items')
            ->where('status', 'pending')
            ->where('created_at', '<=', Carbon::now()->subMinutes(30))
            ->get();

        if ($expiredOrders->isEmpty()) {
            $this->info('Tidak ada pesanan expired saat ini.');
            return;
        }

        foreach ($expiredOrders as $order) {
            DB::beginTransaction();
            try {
                $order->update(['status' => 'cancelled']);

                if ($order->table_id) {
                    $order->table()->update([
                        'status' => 'available',
                        'reserved_until' => null,
                    ]);
                }


                $outlet = Outlet::find($order->outlet_id);
                if ($outlet) {
                    foreach ($order->items as $item) {
                        $product = $outlet->products()->where('products.id', $item->product_id)->first();

                        if ($product) {
                            $newStock = $product->pivot->stock + $item->qty;
                            $outlet->products()->updateExistingPivot($item->product_id, ['stock' => $newStock]);

                            StockHistory::create([
                                'outlet_id' => $order->outlet_id,
                                'product_id' => $item->product_id,
                                'user_id' => null,
                                'type' => 'restore',
                                'quantity' => $item->qty,
                                'final_stock' => $newStock,
                                'reference' => 'Sistem Auto-Cancel (30 Menit): ' . $order->invoice_number,
                            ]);
                        }
                    }
                }

                DB::commit();
                $this->info('Berhasil membatalkan pesanan: ' . $order->invoice_number);

            } catch (\Exception $e) {
                DB::rollBack();
                $this->error('Gagal membatalkan pesanan ' . $order->invoice_number . ': ' . $e->getMessage());
            }
        }
    }
}
