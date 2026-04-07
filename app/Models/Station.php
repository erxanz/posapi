<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\OrderItem;
use App\Models\Order;

class Station extends Model
{
    /** @use HasFactory<\Database\Factories\StationFactory> */
    use HasFactory;

    protected $fillable = [
        'outlet_id',
        'name',
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
            Order::class,
            OrderItem::class,
            'station_id', // FK di order_items
            'id',         // PK di orders
            'id',         // PK di stations
            'order_id'    // FK di order_items ke orders
        );
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    // ===== SECURITY & UTILITY =====

    /**
     * SECURITY: Check apakah user dapat akses station ini
     */
    public function canBeAccessedBy(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        // Manager hanya akses station di outlet miliknya
        if ($user->isManager()) {
            return $this->outlet->owner_id === $user->id;
        }

        // Karyawan hanya akses station di outlet miliknya
        if ($user->isKaryawan()) {
            return $this->outlet_id === $user->outlet_id;
        }

        return false;
    }

    public function scopeUsed($query)
    {
        return $query->where(function ($q) {
            $q->whereHas('products')
            ->orWhereHas('orderItems');
        });
    }

    public function isUsed()
    {
        return $this->products()->exists()
            || $this->orderItems()->exists();
    }
}
