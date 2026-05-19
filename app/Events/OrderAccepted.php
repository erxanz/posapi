<?php

namespace App\Events;

use App\Models\Order;
use App\Models\OrderAcceptance;
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
        return 'OrderAccepted';
    }

    public function broadcastWith(): array
    {
        // payload minimal agar mobile tinggal trigger print
        return [
            'order' => [
                'id' => $this->order->id,
                'invoice_number' => $this->order->invoice_number,
                'customer_name' => $this->order->customer_name,
                'total_price' => $this->order->total_price,
                'status' => $this->order->status,
            ],
            'acceptance' => [
                'scope' => $this->scope,
                'accepted_by' => $this->acceptedBy,
            ],
        ];
    }
}

