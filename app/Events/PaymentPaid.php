<?php

namespace App\Events;

use App\Models\Order; // FIX: Ubah dari Payment ke Order
// use Illuminate\Broadcasting\Channel;
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
        return 'PaymentPaid';
    }

    public function broadcastWith(): array
    {
        return [
            'order' => [
                'id' => $this->order->id,
                'invoice_number' => $this->order->invoice_number,
                'customer_name' => $this->order->customer_name,
                'total_price' => $this->order->total_price,
                'status' => $this->order->status,
                'updated_at' => $this->order->updated_at,
            ],
        ];
    }
}
