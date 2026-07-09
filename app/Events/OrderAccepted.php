<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;

use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderAccepted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Order $order;
    public string $scope;
    public ?int $acceptedBy;

    public function __construct(Order $order, string $scope = 'cashier', ?int $acceptedBy = null)
    {
        $this->order = $order;
        $this->scope = $scope;
        $this->acceptedBy = $acceptedBy;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('orders.outlet.' . $this->order->outlet_id),
            new PrivateChannel('customer-order.' . $this->order->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.accepted';
    }

    public function broadcastWith(): array
    {
        $order = $this->order->loadMissing(['items.product', 'table', 'payment', 'latestAcceptance']);

        return [
            'order' => [
                'id' => $this->order->id,
                'invoice_number' => $this->order->invoice_number,
                'customer_name' => $this->order->customer_name,
                'table_id' => $this->order->table_id,
                'table' => $order->table ? [
                    'id' => $order->table->id,
                    'name' => $order->table->name ?? null,
                    'number' => $order->table->number ?? null,
                ] : null,
                'payment_method' => $this->order->payment_method,
                'subtotal_price' => $this->order->subtotal_price,
                'discount_amount' => $this->order->discount_amount,
                'tax_amount' => $this->order->tax_amount,
                'total_price' => $this->order->total_price,
                'status' => $this->order->status,
                'items' => $order->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'product_name' => $item->product?->name,
                        'qty' => (int) $item->qty,
                        'price' => (int) $item->price,
                        'total_price' => (int) $item->total_price,
                        'notes' => $item->notes,
                    ];
                })->values(),
                'payment' => $order->payment ? [
                    'id' => $order->payment->id,
                    'amount_paid' => (int) $order->payment->amount_paid,
                    'change_amount' => (int) $order->payment->change_amount,
                    'method' => $order->payment->method,
                    'reference_no' => $order->payment->reference_no,
                    'paid_at' => $order->payment->paid_at,
                ] : null,
            ],
            'acceptance' => [
                'scope' => $this->scope,
                'accepted_by' => $this->acceptedBy,
            ],
        ];
    }
}

