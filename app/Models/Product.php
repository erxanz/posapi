<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
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
    ];

    /**
     * Casting biar aman
     */
    protected $casts = [
        'price' => 'integer',
        'cost_price' => 'integer',
        'stock' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Global scope outlet
     */
    protected static function booted()
    {
        static::addGlobalScope('outlet', function (Builder $query) {

            // AMAN: cek auth dulu
            if (Auth::check()) {
                $user = Auth::user();

                // developer bebas
                if ($user->role !== 'developer') {
                    $query->where('outlet_id', $user->outlet_id);
                }
            }

        });
    }

    /**
     * Relasi ke category
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * (Optional) relasi ke outlet
     */
    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }
}
