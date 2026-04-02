<?php

namespace App\Models;

use App\Models\OrderItem;
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
        'total_price',
        'status',
    ];

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

    // SCOPE
    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }
}
