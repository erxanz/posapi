<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'amount_paid',
        'change_amount',
        'method',
        'reference_no',
        'paid_at',
        'paid_by',
    ];

    protected $casts = [
        'amount_paid' => 'integer',
        'change_amount' => 'integer',
        'paid_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function historyTransaction()
    {
        return $this->hasOne(HistoryTransaction::class);
    }
}
