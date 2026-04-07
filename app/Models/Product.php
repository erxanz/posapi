<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Category;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'price',
        'stock',
        'category_id',
        'description',
        'outlet_id',
        'cost_price',
        'image',
        'is_active',
        'station_id',
    ];

    /**
     * Casting biar aman
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'price' => 'integer',
            'cost_price' => 'integer',
            'stock' => 'integer',
        ];
    }

    // ===== RELASI =====

    /**
     * RELASI: Product belongs to Category
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * RELASI: Product belongs to Outlet
     */
    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * RELASI: Product belongs to Station
     */
    public function station()
    {
        return $this->belongsTo(Station::class);
    }

    /**
     * RELASI: Product has many OrderItems
     */
    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    // ===== SECURITY & UTILITY =====

    /**
     * SECURITY: Check apakah user dapat akses product ini
     */
    public function canBeAccessedBy(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        // Manager hanya akses produk di outlet miliknya
        if ($user->isManager()) {
            return $this->outlet->owner_id === $user->id;
        }

        // Karyawan hanya akses produk di outlet miliknya
        if ($user->isKaryawan()) {
            return $this->outlet_id === $user->outlet_id;
        }

        return false;
    }

    /**
     * SCOPE: Filter products by outlet
     */
    public function scopeByOutlet($query, $outletId)
    {
        return $query->where('outlet_id', $outletId);
    }

    /**
     * SCOPE: Only active products
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
