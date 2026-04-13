<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HistoryTransaction extends Model
{
    /** @use HasFactory<\Database\Factories\HistoryTransactionFactory> */
    use HasFactory;

    protected $fillable = [
        'outlet_id',
        'order_id',
        'payment_id',
        'invoice_number',
        'customer_name',
        'subtotal_price',
        'discount_amount',
        'tax_amount',
        'total_price',
        'paid_amount',
        'change_amount',
        'payment_method',
        'paid_at',
        'cashier_id',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_price' => 'integer',
            'discount_amount' => 'integer',
            'tax_amount' => 'integer',
            'total_price' => 'integer',
            'paid_amount' => 'integer',
            'change_amount' => 'integer',
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }
}
