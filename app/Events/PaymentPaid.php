<?php

namespace App\Events;

use App\Models\Payment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentPaid implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $payment;

    /**
     * Create a new event instance.
     */
    public function __construct(Payment $payment)
    {
        $this->payment = $payment;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        // Asumsi relasi payment ke order untuk mendapatkan outlet_id,
        // sesuaikan jika outlet_id ada langsung di tabel payments
        $outletId = $this->payment->order->outlet_id ?? $this->payment->outlet_id;

        return [
            new PrivateChannel('orders.outlet.' . $outletId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'payment.paid';
    }
}
