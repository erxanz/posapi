<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Station extends Model
{
    /** @use HasFactory<\Database\Factories\StationFactory> */
    use HasFactory;

    protected $fillable = [
        'outlet_id',
        'name',
        'code',
        'is_active',
    ];

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function orders()
    {
        return $this->hasManyThrough(
            \App\Models\Order::class,
            \App\Models\OrderItem::class,
            'station_id', // FK di order_items
            'id',         // PK di orders
            'id',         // PK di stations
            'order_id'    // FK di order_items ke orders
        );
    }

    public function isUsed()
    {
        return $this->products()->exists()
            || \App\Models\OrderItem::where('station_id', $this->id)->exists();
    }
}
