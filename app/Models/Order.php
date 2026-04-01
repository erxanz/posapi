<?php

namespace App\Models;

// use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'user_id',
        'invoice_number',
        'total_price',
        'status',
    ];

    // public function items()
    // {
    //     return $this->hasMany(OrderItem::class);
    // }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
