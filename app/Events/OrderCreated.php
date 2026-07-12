<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderCreated implements ShouldBroadcastNow
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
        // Broadcast secara privat ke outlet tempat order dibuat
        return [
            new PrivateChannel('orders.outlet.' . $this->order->outlet_id),
        ];
    }

    /**
     * Alias nama event untuk didengarkan di frontend (Vue/Flutter).
     */
    public function broadcastAs(): string
    {
        return 'order.created';
    }

    /**
     * Data yang dikirim ke Flutter
     */
    public function broadcastWith(): array
    {
        $order = $this->order->loadMissing(['items.product', 'table', 'discount', 'user']);

        return [
            'order' => [
                'id'            => $this->order->id,
                'invoice_number' => $this->order->invoice_number,
                'customer_name' => $this->order->customer_name,
                'table_id'      => $this->order->table_id,
                'cashier_name'  => $order->user ? $order->user->name : null,
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
                'created_at'      => $this->order->created_at,
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
            ],
        ];
    }
}
