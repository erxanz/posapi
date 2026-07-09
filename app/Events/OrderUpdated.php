<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $order;

    /**
     * Create a new event instance.
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('orders.outlet.' . $this->order->outlet_id),
            new PrivateChannel('customer-order.' . $this->order->id), // Opsional: channel khusus untuk order ini
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.updated';
    }

    /**
     * Data yang dikirim ke Flutter
     */
    public function broadcastWith(): array
    {
        return [
            'order' => [
                'id'            => $this->order->id,
                'invoice_number' => $this->order->invoice_number,
                'customer_name' => $this->order->customer_name,
                'total_price'   => $this->order->total_price,
                'status'        => $this->order->status,
                'payment_method' => $this->order->payment_method,
                'updated_at'    => $this->order->updated_at,
            ]
        ];
    }
}
