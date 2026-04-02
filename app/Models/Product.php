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
    ];

    /**
     * Global scope untuk filter berdasarkan outlet_id
     * Metode ini dipanggil otomatis saat model diinisialisasi
     */
    protected static function booted()
    {
        // Menambahkan global scope bernama 'outlet' yang berlaku pada semua query
        static::addGlobalScope('outlet', function ($query) {
            // Cek apakah user sudah login dan role-nya bukan developer
            if (auth()->check() && auth()->user()->role !== 'developer') {
                // Filter data produk hanya untuk outlet yang dimiliki user tersebut
                // Ini memastikan setiap user hanya melihat produk dari outlet mereka
                $query->where('outlet_id', auth()->user()->outlet_id);
            }
            // Developer tidak difilter dan bisa melihat semua produk dari semua outlet
        });
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
