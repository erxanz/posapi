<?php

namespace App\Events;

use App\Models\Order; // FIX: Ubah dari Payment ke Order
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
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
        // Broadcast ke channel cabang terkait
        return [
            new Channel('orders.' . $this->order->outlet_id),
        ];
    }
    
    // (Opsional) Mengatur nama event yang didengar oleh Vue/Flutter
    public function broadcastAs(): string
    {
        return 'PaymentPaid';
    }
}