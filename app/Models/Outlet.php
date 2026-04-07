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
        'owner_id',
    ];

    /**
     * RELASI: Manager (owner) dari outlet
     */
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * RELASI: Karyawan yang bekerja di outlet ini
     */
    public function karyawans()
    {
        return $this->hasMany(User::class, 'outlet_id');
    }

    /**
     * RELASI: Orders di outlet ini
     */
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * RELASI: Meja di outlet ini
     */
    public function tables()
    {
        return $this->hasMany(Table::class);
    }

    /**
     * RELASI: Kategori produk di outlet ini
     */
    public function categories()
    {
        return $this->hasMany(Category::class);
    }

    /**
     * RELASI: Produk di outlet ini
     */
    public function products()
    {
        return $this->hasMany(Product::class);
    }

    /**
     * RELASI: Station di outlet ini
     */
    public function stations()
    {
        return $this->hasMany(Station::class);
    }

    /**
     * SECURITY: Check if owner_id sesuai (untuk manager)
     */
    public function isOwnedBy(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        if ($user->isManager()) {
            return $this->owner_id === $user->id;
        }

        return false;
    }
}
