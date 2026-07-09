<?php

namespace App\Events;

use App\Models\Order; // FIX: Ubah dari Payment ke Order
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow; // Pakai Now biar realtime instan
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentPaid implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $order; // FIX: Ubah variabel ke $order

    /**
     * Create a new event instance.
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('orders.outlet.' . $this->order->outlet_id),
            new PrivateChannel('customer-order.' . $this->order->id),
        ];
    }

    // (Opsional) Mengatur nama event yang didengar oleh Vue/Flutter
    public function broadcastAs(): string
    {
        return 'order.paid';
    }

    public function broadcastWith(): array
    {
        $order = $this->order->loadMissing(['items.product', 'table', 'payments', 'discount']);

        return [
            'order' => [
                'id'            => $this->order->id,
                'invoice_number' => $this->order->invoice_number,
                'customer_name' => $this->order->customer_name,
                'table_id'      => $this->order->table_id,
                'table'         => $order->table ? [
                    'id'     => $order->table->id,
                    'name'   => $order->table->name ?? null,
                    'number' => $order->table->number ?? null,
                ] : null,
                'subtotal_price'  => $this->order->subtotal_price,
                'discount_amount' => $this->order->discount_amount,
                'tax_amount'      => $this->order->tax_amount,
                'total_price'     => $this->order->total_price,
                'status'          => $this->order->status,
                'payment_method'  => $this->order->payment_method,
                'updated_at'      => $this->order->updated_at,
                'items' => $order->items->map(function ($item) {
                    return [
                        'id'           => $item->id,
                        'product_id'   => $item->product_id,
                        'product_name' => $item->product?->name,
                        'qty'          => (int) $item->qty,
                        'price'        => (int) $item->price,
                        'total_price'  => (int) $item->total_price,
                        'notes'        => $item->notes,
                    ];
                })->values(),
                'payment' => $order->payments->last() ? [
                    'id'           => $order->payments->last()->id,
                    'amount_paid'  => (int) $order->payments->last()->amount_paid,
                    'change_amount' => (int) $order->payments->last()->change_amount,
                    'method'       => $order->payments->last()->method,
                    'reference_no' => $order->payments->last()->reference_no,
                    'paid_at'      => $order->payments->last()->paid_at,
                ] : null,
            ],
        ];
    }
}
