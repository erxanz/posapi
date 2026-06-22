<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'station_id',
        'qty',
        'price',
        'total_price',
        'cancelled_qty',
        'status',
        'notes',
    ];

    protected $casts = [
        'qty' => 'integer',
        'cancelled_qty' => 'integer',
        'price' => 'integer',
        'total_price' => 'integer',
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PARTIAL_CANCELLED = 'partial_cancelled';
    public const STATUS_CANCELLED = 'cancelled';

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Apply a cancellation to this item.
     *
     * @param int $cancelQty
     * @return void
     */
    public function applyCancellation(int $cancelQty): void
    {
        $cancelQty = max(0, (int) $cancelQty);

        $this->cancelled_qty = ($this->cancelled_qty ?? 0) + $cancelQty;

        // Ensure cancelled_qty does not exceed qty
        if ($this->cancelled_qty > $this->qty) {
            $this->cancelled_qty = $this->qty;
        }

        $remaining = max(0, $this->qty - $this->cancelled_qty);
        $this->total_price = $remaining * (int) $this->price;

        if ($this->cancelled_qty === 0) {
            $this->status = self::STATUS_ACTIVE;
        } elseif ($this->cancelled_qty >= $this->qty) {
            $this->status = self::STATUS_CANCELLED;
        } else {
            $this->status = self::STATUS_PARTIAL_CANCELLED;
        }

        $this->save();
    }
}