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
    protected $description = 'Membatalkan pesanan pending yang kedaluwarsa dan mengembalikan stok';

    // Order QRIS/online: 30 menit, sesuai durasi wajar pembayaran via Midtrans.
    private const QRIS_TIMEOUT_MINUTES = 30;

    // Order cash: jauh lebih panjang. Order cash pending itu wajar (menunggu
    // kasir accept/checkout, pelanggan masih makan di tempat) - bukan soal
    // pembayaran kedaluwarsa seperti QRIS. 30 menit terlalu agresif dan bisa
    // membatalkan order yang masih aktif diproses secara normal.
    private const CASH_TIMEOUT_MINUTES = 180;

    public function handle()
    {
        $expiredOrders = Order::with('items')
            ->where('status', 'pending')
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('payment_method', 'qris')
                        ->where('created_at', '<=', Carbon::now()->subMinutes(self::QRIS_TIMEOUT_MINUTES));
                })->orWhere(function ($q) {
                    $q->where(function ($qq) {
                        $qq->where('payment_method', 'cash')->orWhereNull('payment_method');
                    })->where('created_at', '<=', Carbon::now()->subMinutes(self::CASH_TIMEOUT_MINUTES));
                });
            })
            ->get();

        if ($expiredOrders->isEmpty()) {
            $this->info('Tidak ada pesanan expired saat ini.');
            return;
        }

        foreach ($expiredOrders as $order) {
            DB::beginTransaction();
            try {
                // Kembalikan kuota pemakaian diskon
                $order->decrementDiscountUsage();

                $order->update(['status' => 'cancelled']);

                if ($order->table_id) {
                    $order->table()->update([
                        'status' => 'available',
                        'reserved_until' => null,
                    ]);
                }


                $outlet = Outlet::find($order->outlet_id);
                if ($outlet) {
                    $timeoutLabel = $order->payment_method === 'qris'
                        ? self::QRIS_TIMEOUT_MINUTES . ' Menit'
                        : self::CASH_TIMEOUT_MINUTES . ' Menit';

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
                                'reference' => "Sistem Auto-Cancel ({$timeoutLabel}): " . $order->invoice_number,
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
