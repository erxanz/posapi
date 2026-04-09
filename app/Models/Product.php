<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_id',
        'name',
        'category_id',
        'description',
        'cost_price',
        'image',
        'station_id',
    ];

    /**
     * Casting biar aman
     */
    protected $casts = [
        'cost_price' => 'integer',
    ];

    /**
     * Relasi ke category
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Relasi ke owner catalog
     */
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Relasi distribusi produk ke banyak outlet
     */
    public function outlets()
    {
        return $this->belongsToMany(Outlet::class)
            ->withPivot(['price', 'stock', 'is_active'])
            ->withTimestamps();
    }

    /**
    * (Optional) relasi ke station
    */
    public function station()
    {
        return $this->belongsTo(Station::class);
    }
}
