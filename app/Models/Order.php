<?php

namespace App\Models;

use App\Models\OrderItem;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Table;
use App\Models\User;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'outlet_id',
        'user_id',
        'table_id',
        'customer_name',
        'notes',
        'invoice_number',
        'subtotal_price',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax_type',
        'tax_value',
        'tax_amount',
        'total_price',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_price' => 'integer',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'integer',
            'tax_value' => 'decimal:2',
            'tax_amount' => 'integer',
            'total_price' => 'integer',
        ];
    }

    // RELASI
    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function table()
    {
        return $this->belongsTo(Table::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    // SCOPE
    public function scopeOpen($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
