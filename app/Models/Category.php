<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'outlet_id',
    ];

    // ===== RELASI =====

    /**
     * RELASI: Category belongs to Outlet
     */
    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * RELASI: Category has many Products
     */
    public function products()
    {
        return $this->hasMany(Product::class);
    }

    // ===== SECURITY =====

    /**
     * SECURITY: Check apakah user dapat akses category ini
     */
    public function canBeAccessedBy(User $user): bool
    {
        if ($user->isDeveloper()) {
            return true;
        }

        // Manager hanya akses category di outlet miliknya
        if ($user->isManager()) {
            return $this->outlet->owner_id === $user->id;
        }

        // Karyawan hanya akses category di outlet miliknya
        if ($user->isKaryawan()) {
            return $this->outlet_id === $user->outlet_id;
        }

        return false;
    }
}
