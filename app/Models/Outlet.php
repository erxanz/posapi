<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\Models\Order;

class Outlet extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'image',
        'phone_number_outlet',
        'address_outlet',
        'owner_id',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function karyawans()
    {
        return $this->hasMany(User::class, 'outlet_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function historyTransactions()
    {
        return $this->hasMany(HistoryTransaction::class);
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'outlet_product')
                    ->withPivot('stock', 'price', 'is_active')
                    ->wherePivot('is_active', true);
    }
}
