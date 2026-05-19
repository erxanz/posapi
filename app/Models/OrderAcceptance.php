<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderAcceptance extends Model
{
    protected $fillable = [
        'order_id',
        'accepted_by',
        'scope',
        'accepted_at',
        'printed_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
        'printed_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }
}

