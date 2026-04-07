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
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'price' => 'integer',
            'total_price' => 'integer',
        ];
    }

    // ===== RELASI =====

    /**
     * RELASI: OrderItem belongs to Order
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * RELASI: OrderItem belongs to Product
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * RELASI: OrderItem belongs to Station
     */
    public function station()
    {
        return $this->belongsTo(Station::class);
    }

    // ===== UTILITY =====

    /**
     * Hitung total price = qty * price
     */
    public function calculateTotal(): int
    {
        return $this->qty * $this->price;
    }
}
